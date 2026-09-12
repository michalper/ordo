<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ConversationMessage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only conversation view for ordo_conversation_message — the "conversation view tied to the
 * customer record" from ROADMAP.md's Two-way SMS/WhatsApp conversation handling item. A plain
 * grid rather than a customer-edit-page tab/block: this module has no existing extension point
 * on the core customer edit page to hang a tab off of (unlike, say, Model\ResourceModel\
 * MessageLog\Grid\Collection's own customer_name/customer_email join, which is the same "resolve
 * customer_id to something readable" pattern this grid reuses), so a dedicated grid - filterable
 * by Customer - is the smallest coherent way to ship a real conversation view now. Guarded by its
 * own ACL resource, same as message_log.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::conversation_message';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::conversation_message');
        $resultPage->getConfig()->getTitle()->prepend(__('Conversations'));

        return $resultPage;
    }
}
