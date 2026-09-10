<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\WhatsAppTemplate;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;
use Ordo\Automation\Model\WhatsAppTemplateFactory;

/**
 * Invoked via a POST-with-confirm link (Magento_Ui's "post": true action flag &
 * form-key validation, standard for HttpPostActionInterface controllers) - see
 * Ui\Component\Listing\Column\WhatsAppTemplateActions/AbstractEntityActionsColumn.
 */
class Delete extends AbstractWhatsAppTemplateAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly WhatsAppTemplateFactory $whatsAppTemplateFactory,
        private readonly WhatsAppTemplateResource $whatsAppTemplateResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing WhatsApp template id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $template = $this->whatsAppTemplateFactory->create();
            $this->whatsAppTemplateResource->load($template, $entityId);
            $this->whatsAppTemplateResource->delete($template);

            $this->messageManager->addSuccessMessage(__('The WhatsApp template has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the WhatsApp template: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
