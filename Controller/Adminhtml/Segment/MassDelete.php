<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment;

/**
 * See Campaign\MassDelete's own docblock for the shared Filter/collection pattern - no cache tag
 * to flush here (segments have no equivalent of CampaignDispatcher's cached trigger lookup).
 * Deletes through SegmentResource directly, same as the single-segment Delete controller.
 */
class MassDelete extends AbstractSegmentAction implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly SegmentCollectionFactory $segmentCollectionFactory,
        private readonly SegmentResource $segmentResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->segmentCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var Segment $entity */
        // Condition rows cascade-delete via the FK ON DELETE CASCADE in db_schema.xml.
        $this->segmentResource->delete($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 segment(s) have been deleted.', $count);
    }
}
