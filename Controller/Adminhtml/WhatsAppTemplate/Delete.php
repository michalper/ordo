<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Phrase;
use Ordo\Automation\Controller\Adminhtml\Shared\DeletesEntityTrait;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\WhatsAppTemplateActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    use DeletesEntityTrait;

    public function __construct(
        Context $context,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource
    ) {
        parent::__construct($context);
    }

    protected function deleteEntity(int $entityId): void
    {
        $template = $this->whatsAppTemplateFactory->create();
        $this->whatsAppTemplateResource->load($template, $entityId);
        $this->whatsAppTemplateResource->delete($template);
    }

    protected function getMissingIdMessage(): Phrase
    {
        return __('Missing WhatsApp template id.');
    }

    protected function getDeletedMessage(): Phrase
    {
        return __('The WhatsApp template has been deleted.');
    }

    protected function getDeleteErrorMessage(\Throwable $e): Phrase
    {
        return __('Could not delete the WhatsApp template: %1', $e->getMessage());
    }
}
