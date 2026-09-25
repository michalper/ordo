<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PushSubscription;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only view of ordo_push_subscription — closes the round-2 admin UI/UX audit finding
 * (ROADMAP.md) that Controller\Track\RegisterPushSubscription/UnregisterPushSubscription had no
 * admin-side visibility at all. Guarded by its own dedicated ACL resource (push_subscriptions)
 * rather than the broader campaigns resource, matching every other diagnostic grid in this
 * module (Message Log, Cron Run Log, ...).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::push_subscriptions';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::push_subscriptions');
        $resultPage->getConfig()->getTitle()->prepend(__('Push Subscriptions'));

        return $resultPage;
    }
}
