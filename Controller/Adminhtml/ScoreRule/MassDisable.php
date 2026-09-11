<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;

/**
 * See MassEnable's own docblock - same pattern, opposite direction.
 */
class MassDisable extends AbstractScoreRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly ScoreRuleCollectionFactory $scoreRuleCollectionFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->scoreRuleCollectionFactory->create());

        $count = 0;
        foreach ($collection as $scoreRule) {
            /** @var \Ordo\Automation\Model\ScoreRule $scoreRule */
            $scoreRule->setEnabled(false);
            $this->scoreRuleResource->save($scoreRule);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 score rule(s) have been disabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
