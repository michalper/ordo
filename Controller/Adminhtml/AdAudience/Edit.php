<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\AdAudience;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdAudienceFactory;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;

class Edit extends AbstractAdAudienceAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly AdAudienceFactory $adAudienceFactory,
        private readonly AdAudienceResource $adAudienceResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->adAudienceFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->adAudienceResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_ad_audience';
    }

    protected function editEntityLabel(): string
    {
        return 'Ad Audience';
    }

    protected function missingEntityLabel(): string
    {
        return 'ad audience';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var AdAudience $entity */
        return (string) $entity->getName();
    }
}
