<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\FreeGiftOffer\FreeGiftOfferSaveProcessor;

/**
 * Persists the offer row plus its tier/product child rows in one request. The actual
 * persistence logic lives in FreeGiftOfferSaveProcessor — this controller only handles the
 * HTTP request/response, redirects, and admin messages.
 */
class Save extends AbstractFreeGiftOfferAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly FreeGiftOfferSaveProcessor $saveProcessor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var array<string, mixed> $data */
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);

        try {
            $offer = $this->saveProcessor->process($data);

            $this->messageManager->addSuccessMessage(__('The free gift offer has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $offer->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the free gift offer: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }
}
