<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\MessageLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only view of ordo_message_log — the only way to see whether a send_sms campaign action
 * actually delivered without querying the database directly (Model\Sms\MessageLogWriter records
 * every send/opt-out/failure here, Controller\Sms\StatusCallback updates status on Twilio's
 * delivery-status webhook). Guarded by its own dedicated ACL resource (message_log) rather than
 * the broader campaigns resource, so access can be granted independently of full campaign
 * management permissions.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::message_log';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::message_log';
    }

    protected function pageTitle(): Phrase
    {
        return __('Message Log');
    }
}
