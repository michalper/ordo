<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersEditPageTrait;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

class Edit extends AbstractWhatsAppTemplateAction implements HttpGetActionInterface
{
    use RendersEditPageTrait;

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource
    ) {
        parent::__construct($context);
    }

    protected function newEntity(): AbstractModel
    {
        return $this->whatsAppTemplateFactory->create();
    }

    protected function loadEntityById(AbstractModel $entity, int $entityId): void
    {
        $this->whatsAppTemplateResource->load($entity, $entityId);
    }

    protected function registryKey(): string
    {
        return 'ordo_whatsapp_template';
    }

    protected function editEntityLabel(): string
    {
        return 'WhatsApp Template';
    }

    protected function missingEntityLabel(): string
    {
        return 'WhatsApp template';
    }

    protected function entityDisplayName(AbstractModel $entity): string
    {
        /** @var WhatsAppTemplate $entity */
        return (string) $entity->getName();
    }
}
