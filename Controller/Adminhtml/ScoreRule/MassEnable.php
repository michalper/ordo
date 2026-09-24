<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Shared\RunsMassActionTrait;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ScoreRule;

/**
 * Grid mass-action counterpart to the single-rule enable/disable toggle already available from
 * Score Rule Edit — closes the admin-platform ROADMAP.md gap where this grid's own
 * selectionsColumn rendered checkboxes with no massaction behind them. Same
 * Ui\Component\MassAction\Filter pattern as Controller\Adminhtml\Campaign\MassEnable, adapted to
 * this entity's plain Factory + ResourceModel::save() (ScoreRule has no repository interface).
 */
class MassEnable extends AbstractScoreRuleAction implements HttpPostActionInterface
{
    use RunsMassActionTrait;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly ScoreRuleCollectionFactory $scoreRuleCollectionFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    protected function getMassActionCollection(): iterable
    {
        return $this->filter->getCollection($this->scoreRuleCollectionFactory->create());
    }

    protected function applyToEntity(object $entity): void
    {
        /** @var ScoreRule $entity */
        $entity->setEnabled(true);
        $this->scoreRuleResource->save($entity);
    }

    protected function getMassActionSuccessMessage(int $count): Phrase
    {
        return __('A total of %1 score rule(s) have been enabled.', $count);
    }
}
