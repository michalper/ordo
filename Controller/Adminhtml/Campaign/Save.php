<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;

/**
 * Persists the campaign row plus its condition/action child rows in one request. Child rows
 * are posted by the form's dynamicRows fields as plain arrays — this always deletes and
 * re-inserts them rather than diffing, which is simple and correct for the small row counts
 * a campaign realistically has (a handful of conditions/actions, not hundreds).
 */
class Save extends AbstractCampaignAction
{
    public function __construct(
        Context $context,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CampaignConditionFactory $campaignConditionFactory,
        private readonly CampaignConditionResource $campaignConditionResource,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignActionResource $campaignActionResource,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);
        $campaign = $this->campaignFactory->create();

        if ($entityId) {
            $this->campaignResource->load($campaign, $entityId);
        }

        $campaign->setName((string) ($data['name'] ?? ''));
        $campaign->setTriggerEvent((string) ($data['trigger_event'] ?? ''));
        $campaign->setEnabled(!empty($data['enabled']));

        try {
            $this->campaignResource->save($campaign);
            $this->saveChildRows(
                (int) $campaign->getEntityId(),
                (array) ($data['conditions'] ?? []),
                (array) ($data['actions'] ?? [])
            );

            $this->messageManager->addSuccessMessage(__('The campaign has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $campaign->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the campaign: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $conditionRows
     * @param array<int, array<string, mixed>> $actionRows
     */
    private function saveChildRows(int $campaignId, array $conditionRows, array $actionRows): void
    {
        $conditions = $this->campaignConditionCollectionFactory->create();
        $conditions->addCampaignFilter($campaignId);
        foreach ($conditions as $existing) {
            $this->campaignConditionResource->delete($existing);
        }

        $sortOrder = 0;
        foreach ($conditionRows as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $condition = $this->campaignConditionFactory->create();
            $condition->setData([
                'campaign_id' => $campaignId,
                'type' => (string) $row['type'],
                'params' => $this->normalizeParams($row['params_json'] ?? ''),
                'sort_order' => $sortOrder++,
            ]);
            $this->campaignConditionResource->save($condition);
        }

        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        foreach ($actions as $existing) {
            $this->campaignActionResource->delete($existing);
        }

        $sortOrder = 0;
        foreach ($actionRows as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $action = $this->campaignActionFactory->create();
            $action->setData([
                'campaign_id' => $campaignId,
                'type' => (string) $row['type'],
                'params' => $this->normalizeParams($row['params_json'] ?? ''),
                'sort_order' => $sortOrder++,
            ]);
            $this->campaignActionResource->save($action);
        }
    }

    /**
     * Stores whatever was typed as valid JSON if possible; falls back to an empty object
     * rather than rejecting the save outright — a condition/action with unparsable params
     * simply won't find the keys it expects at dispatch time (fails closed, doesn't crash).
     */
    private function normalizeParams(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '{}';
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? json_encode($decoded) : '{}';
    }
}
