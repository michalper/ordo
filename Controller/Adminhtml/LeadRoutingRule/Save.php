<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Save extends AbstractLeadRoutingRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly LeadRoutingRuleFactory $leadRoutingRuleFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var array<string, mixed> $data */
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);

        try {
            $leadRoutingRule = $this->leadRoutingRuleFactory->create();
            if ($entityId) {
                $this->leadRoutingRuleResource->load($leadRoutingRule, $entityId);
            }

            $leadRoutingRule->setName((string) ($data['name'] ?? ''));
            $leadRoutingRule->setAttributeCode((string) ($data['attribute_code'] ?? ''));
            $leadRoutingRule->setOperator((string) ($data['operator'] ?? ''));
            $leadRoutingRule->setValue((string) ($data['value'] ?? ''));
            $leadRoutingRule->setReps($this->encodeReps($data['reps']['reps'] ?? []));
            $leadRoutingRule->setEnabled((bool) ($data['enabled'] ?? false));
            $leadRoutingRule->setSortOrder((int) ($data['sort_order'] ?? 0));

            $this->leadRoutingRuleResource->save($leadRoutingRule);

            $this->messageManager->addSuccessMessage(__('The lead routing rule has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $leadRoutingRule->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the lead routing rule: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * The "reps" dynamicRows field (dataScope "reps", component name "reps") posts as
     * $data['reps']['reps'], the standard Magento_Ui dataScope-then-component-name nesting for
     * a dynamicRows field whose own name matches its dataScope - confirmed against
     * Model\Campaign\CampaignSaveProcessor's own identical $data['triggers']['triggers'] read
     * for its "triggers" dynamicRows field. Each row is keyed by an internal row id, e.g.
     * {"123": {"email": "...", "name": "...", "phone": "..."}} - reindexed here into a plain
     * JSON array (LeadAssigner::assign() reads it as a 0-indexed pool), dropping any row with a
     * blank email (an admin who added then emptied a row, not a real pool entry).
     */
    private function encodeReps(mixed $rawReps): string
    {
        if (!is_array($rawReps)) {
            return '[]';
        }

        $reps = [];
        foreach ($rawReps as $row) {
            if (!is_array($row) || trim((string) ($row['email'] ?? '')) === '') {
                continue;
            }

            $reps[] = [
                'email' => trim((string) $row['email']),
                'name' => trim((string) ($row['name'] ?? '')),
                'phone' => trim((string) ($row['phone'] ?? '')),
            ];
        }

        return json_encode($reps) ?: '[]';
    }
}
