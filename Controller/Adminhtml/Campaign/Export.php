<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * A JSON download of one campaign's full graph (triggers + conditions + actions), so a
 * definition can be backed up or moved between environments - the first export capability in
 * the module beyond GDPR customer-data export (Controller\Adminhtml\Gdpr\Export, whose
 * RawFactory-download pattern this mirrors exactly). Deliberately excludes entity_id/campaign_id
 * from every child row - re-import assigns fresh ids, so the export payload only ever needs to
 * carry the parts that actually describe the campaign (trigger_event/type/params/sort_order/
 * delay_minutes), not this environment's own row identities.
 */
class Export extends AbstractCampaignAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CampaignTriggerCollectionFactory $triggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $conditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $actionCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if ($entityId <= 0) {
            $this->messageManager->addErrorMessage(__('Missing campaign id.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $campaign = $this->campaignFactory->create();
        $this->campaignResource->load($campaign, $entityId);
        if (!$campaign->getEntityId()) {
            $this->messageManager->addErrorMessage(__('Campaign not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $triggers = [];
        foreach ($this->triggerCollectionFactory->create()->addCampaignFilter($entityId) as $trigger) {
            /** @var \Ordo\Automation\Model\CampaignTrigger $trigger */
            $triggers[] = [
                'trigger_event' => $trigger->getTriggerEvent(),
                'params' => $trigger->getParams(),
            ];
        }

        $conditions = [];
        foreach ($this->conditionCollectionFactory->create()->addCampaignFilter($entityId) as $condition) {
            /** @var \Ordo\Automation\Model\CampaignCondition $condition */
            $conditions[] = [
                'type' => $condition->getType(),
                'params' => $condition->getParams(),
                'sort_order' => $condition->getSortOrder(),
            ];
        }

        $actions = [];
        foreach ($this->actionCollectionFactory->create()->addCampaignFilter($entityId) as $action) {
            /** @var \Ordo\Automation\Model\CampaignAction $action */
            $actions[] = [
                'type' => $action->getType(),
                'params' => $action->getParams(),
                'sort_order' => $action->getSortOrder(),
                'delay_minutes' => $action->getDelayMinutes(),
            ];
        }

        $payload = [
            'export_type' => 'ordo_campaign',
            'name' => $campaign->getName(),
            'enabled' => $campaign->isEnabled(),
            'condition_logic' => $campaign->getConditionLogic(),
            'triggers' => $triggers,
            'conditions' => $conditions,
            'actions' => $actions,
        ];

        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'application/json');
        $result->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="ordo-campaign-export-%d.json"', $entityId)
        );
        $result->setContents((string) json_encode($payload, JSON_PRETTY_PRINT));

        return $result;
    }
}
