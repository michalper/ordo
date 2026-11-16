<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PushSubscription;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only view of ordo_push_subscription — closes the round-2 admin UI/UX audit finding
 * (ROADMAP.md) that Controller\Track\RegisterPushSubscription/UnregisterPushSubscription had no
 * admin-side visibility at all. Guarded by its own dedicated ACL resource (push_subscriptions)
 * rather than the broader campaigns resource, matching every other diagnostic grid in this
 * module (Message Log, Cron Run Log, ...).
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::push_subscriptions';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::push_subscriptions';
    }

    protected function pageTitle(): Phrase
    {
        return __('Push Subscriptions');
    }
}
