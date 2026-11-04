<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website as ScoreRuleWebsiteResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRuleFactory;
use Ordo\Automation\Model\ScoreRuleWebsiteFactory;

class Save extends AbstractScoreRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource,
        private readonly ScoreRuleWebsiteFactory $scoreRuleWebsiteFactory,
        private readonly ScoreRuleWebsiteResource $scoreRuleWebsiteResource,
        private readonly ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory
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
            $scoreRule = $this->scoreRuleFactory->create();
            if ($entityId) {
                $this->scoreRuleResource->load($scoreRule, $entityId);
            }

            $scoreRule->setAttributeCode((string) ($data['attribute_code'] ?? ''));
            $scoreRule->setOperator((string) ($data['operator'] ?? ''));
            $scoreRule->setValue((string) ($data['value'] ?? ''));
            $scoreRule->setPoints((int) ($data['points'] ?? 0));
            $scoreRule->setEnabled((bool) ($data['enabled'] ?? false));
            $scoreRule->setSortOrder((int) ($data['sort_order'] ?? 0));

            $this->scoreRuleResource->save($scoreRule);
            $this->saveWebsites((int) $scoreRule->getEntityId(), (array) ($data['website_ids'] ?? []));

            $this->messageManager->addSuccessMessage(__('The score rule has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $scoreRule->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the score rule: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * No rows at all means unscoped (matches customers on every website) - see docs/adr/0014 -
     * so an empty posted selection (the common case; most installs are single-website) correctly
     * leaves this rule with zero website rows rather than writing anything. Mirrors
     * Model\Campaign\CampaignSaveProcessor::saveWebsites() exactly.
     *
     * @param array<int, string|int> $websiteIds
     */
    private function saveWebsites(int $scoreRuleId, array $websiteIds): void
    {
        $existingWebsites = $this->scoreRuleWebsiteCollectionFactory->create();
        $existingWebsites->addScoreRuleFilter($scoreRuleId);
        foreach ($existingWebsites as $existing) {
            $this->scoreRuleWebsiteResource->delete($existing);
        }

        $seen = [];
        foreach ($websiteIds as $websiteId) {
            $websiteId = (int) $websiteId;
            if ($websiteId <= 0 || isset($seen[$websiteId])) {
                continue;
            }
            $seen[$websiteId] = true;

            $website = $this->scoreRuleWebsiteFactory->create();
            $website->setData([
                'score_rule_id' => $scoreRuleId,
                'website_id' => $websiteId,
            ]);
            $this->scoreRuleWebsiteResource->save($website);
        }
    }
}
