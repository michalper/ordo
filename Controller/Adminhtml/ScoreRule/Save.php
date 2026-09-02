<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRuleFactory;

class Save extends AbstractScoreRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource
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
}
