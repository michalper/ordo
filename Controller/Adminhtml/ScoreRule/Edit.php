<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRuleFactory;

class Edit extends AbstractScoreRuleAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->scoreRuleFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->scoreRuleResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_score_rule';
    }

    protected function editEntityLabel(): string
    {
        return 'Score Rule';
    }

    protected function missingEntityLabel(): string
    {
        return 'score rule';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var ScoreRule $entity */
        return (string) $entity->getAttributeCode();
    }
}
