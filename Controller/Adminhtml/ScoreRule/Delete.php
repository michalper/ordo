<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRuleFactory;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\ScoreRuleActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractScoreRuleAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $scoreRule = $this->scoreRuleFactory->create();
        $this->scoreRuleResource->load($scoreRule, $entityId);
        $this->scoreRuleResource->delete($scoreRule);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing score rule id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The score rule has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the score rule: %1', $e->getMessage());
    }
}
