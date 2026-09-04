<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Model\Queue\SegmentBulkActionConsumer;
use Ordo\Automation\Model\Queue\SegmentBulkActionPublisher;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Model\SegmentFactory;

/**
 * Resolves a segment's current members synchronously (a bounded aggregate-query resolve, not an
 * N+1 loop) and queues the requested bulk action against that fixed customer list, so an admin
 * gets an accurate "queued for N customers" count immediately while the actual per-customer work
 * runs off the request thread via SegmentBulkActionConsumer.
 */
class BulkAction extends AbstractSegmentAction implements HttpPostActionInterface
{
    private const array ACTION_LABELS = [
        SegmentBulkActionConsumer::ACTION_ADD_TAG => 'Add Tag',
        SegmentBulkActionConsumer::ACTION_ADD_POINTS => 'Add Points',
    ];

    public function __construct(
        Context $context,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        private readonly SegmentBulkActionPublisher $segmentBulkActionPublisher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $segmentId = (int) $this->getRequest()->getParam('segment_id');
        $actionType = (string) $this->getRequest()->getParam('action_type');
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('*/*/edit', ['entity_id' => $segmentId]);

        try {
            $params = $this->validateAndBuildParams($segmentId, $actionType);

            $customerIds = $this->segmentMemberResolver->getMatchingCustomerIds($segmentId);

            if ($customerIds === []) {
                $this->messageManager->addWarningMessage(__('No customers currently match this segment.'));
                return $resultRedirect;
            }

            $this->segmentBulkActionPublisher->publish($segmentId, $actionType, $params, $customerIds);

            $this->messageManager->addSuccessMessage(__(
                'Queued "%1" for %2 matching customer(s).',
                self::ACTION_LABELS[$actionType] ?? $actionType,
                count($customerIds)
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $resultRedirect;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAndBuildParams(int $segmentId, string $actionType): array
    {
        if ($segmentId <= 0) {
            throw new LocalizedException(__('Invalid segment.'));
        }

        $segment = $this->segmentFactory->create();
        $this->segmentResource->load($segment, $segmentId);

        if (!$segment->getEntityId()) {
            throw new LocalizedException(__('This segment no longer exists.'));
        }

        if (!isset(self::ACTION_LABELS[$actionType])) {
            throw new LocalizedException(__('Invalid bulk action type.'));
        }

        if ($actionType === SegmentBulkActionConsumer::ACTION_ADD_TAG) {
            $tag = trim((string) $this->getRequest()->getParam('tag'));

            if ($tag === '') {
                throw new LocalizedException(__('Please enter a tag.'));
            }

            return ['tag' => $tag];
        }

        $points = $this->getRequest()->getParam('points');

        if ($points === null || $points === '' || !is_numeric($points)) {
            throw new LocalizedException(__('Please enter a valid number of points.'));
        }

        return ['points' => (int) $points];
    }
}
