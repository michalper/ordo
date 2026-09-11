<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\SegmentFactory;

/**
 * A JSON download of one segment's full graph (conditions, including nested "group" rows), so a
 * definition can be backed up or moved between environments - see
 * Controller\Adminhtml\Campaign\Export's own docblock for the shared reasoning (same
 * RawFactory-download pattern as Controller\Adminhtml\Gdpr\Export, same "no entity ids in the
 * payload" choice since re-import always assigns fresh ones).
 */
class Export extends AbstractSegmentAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentConditionCollectionFactory $conditionCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if ($entityId <= 0) {
            $this->messageManager->addErrorMessage(__('Missing segment id.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $segment = $this->segmentFactory->create();
        $this->segmentResource->load($segment, $entityId);
        if (!$segment->getEntityId()) {
            $this->messageManager->addErrorMessage(__('Segment not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $conditions = [];
        foreach ($this->conditionCollectionFactory->create()->addSegmentFilter($entityId) as $condition) {
            /** @var \Ordo\Automation\Model\SegmentCondition $condition */
            // A type === 'group' row's params is already the {logic, conditions: [...]} shape
            // SegmentSaveProcessor::normalizeGroupRow() builds - getParams() decodes it as-is,
            // no special-casing needed here.
            $conditions[] = [
                'type' => $condition->getType(),
                'params' => $condition->getParams(),
                'sort_order' => $condition->getSortOrder(),
            ];
        }

        $payload = [
            'export_type' => 'ordo_segment',
            'name' => $segment->getName(),
            'enabled' => $segment->isEnabled(),
            'condition_logic' => $segment->getConditionLogic(),
            'conditions' => $conditions,
        ];

        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'application/json');
        $result->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="ordo-segment-export-%d.json"', $entityId)
        );
        $result->setContents((string) json_encode($payload, JSON_PRETTY_PRINT));

        return $result;
    }
}
