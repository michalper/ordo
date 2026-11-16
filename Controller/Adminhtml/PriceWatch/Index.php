<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PriceWatch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only view of ordo_price_watch_subscription — closes the round-2 admin UI/UX audit finding
 * (ROADMAP.md) that Controller\Track\RegisterPriceWatch had no admin-side visibility at all.
 * Guarded by its own dedicated ACL resource (price_watch_subscriptions), matching every other
 * diagnostic grid in this module.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::price_watch_subscriptions';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::price_watch_subscriptions';
    }

    protected function pageTitle(): Phrase
    {
        return __('Price Watch Subscriptions');
    }
}
