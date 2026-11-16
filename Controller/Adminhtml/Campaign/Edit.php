<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

class Edit extends AbstractCampaignAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->campaignFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->campaignResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_campaign';
    }

    protected function editEntityLabel(): string
    {
        return 'Campaign';
    }

    protected function missingEntityLabel(): string
    {
        return 'campaign';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var Campaign $entity */
        return (string) $entity->getName();
    }
}
