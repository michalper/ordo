<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\MessageLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only view of ordo_message_log — the only way to see whether a send_sms campaign action
 * actually delivered without querying the database directly (Model\Sms\MessageLogWriter records
 * every send/opt-out/failure here, Controller\Sms\StatusCallback updates status on Twilio's
 * delivery-status webhook). Guarded by the campaigns ACL resource rather than a new one of its
 * own, same reasoning as Rfm\Index: this is an operational view of what campaign actions already
 * did, not a separate feature with its own permission boundary.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(__('Message Log'));

        return $resultPage;
    }
}
