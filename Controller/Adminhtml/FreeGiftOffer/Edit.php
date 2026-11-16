<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;

class Edit extends AbstractFreeGiftOfferAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly FreeGiftOfferFactory $offerFactory,
        private readonly FreeGiftOfferResource $offerResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->offerFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->offerResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_free_gift_offer';
    }

    protected function editEntityLabel(): string
    {
        return 'Free Gift Offer';
    }

    protected function missingEntityLabel(): string
    {
        return 'free gift offer';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var FreeGiftOffer $entity */
        return (string) $entity->getName();
    }
}
