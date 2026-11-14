<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ConversationMessage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\Conversation\OutboundReplySender;

/**
 * The POST half of the "two-way conversation replies" roadmap item closed by this change - the
 * only new path that ever sends FROM the admin back to a customer over SMS/WhatsApp. A thin
 * controller on purpose: every real decision (consent re-check, which sender to call, writing the
 * new outbound row) lives in Model\Conversation\OutboundReplySender, not here and not in
 * Model\Conversation\InboundMessageProcessor - see that service's own docblock and docs/adr/0059.
 */
class Reply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::conversation_message';

    public function __construct(
        Context $context,
        private readonly OutboundReplySender $outboundReplySender
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $body = (string) $this->getRequest()->getParam('body');

        try {
            $this->outboundReplySender->reply($entityId, $body);
            $this->messageManager->addSuccessMessage(__('Reply sent.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not send the reply: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
