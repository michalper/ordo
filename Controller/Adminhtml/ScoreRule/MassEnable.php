<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;

/**
 * Grid mass-action counterpart to the single-rule enable/disable toggle already available from
 * Score Rule Edit — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassEnable, adapted to
 * this entity's plain Factory + ResourceModel::save() (ScoreRule has no repository interface).
 */
class MassEnable extends AbstractScoreRuleAction implements HttpPostActionInterface
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
            $scoreRule->setEnabled(true);
            $this->scoreRuleResource->save($scoreRule);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 score rule(s) have been enabled.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
