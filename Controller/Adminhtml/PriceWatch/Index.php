<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\PriceWatch;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only view of ordo_price_watch_subscription — closes the round-2 admin UI/UX audit finding
 * (ROADMAP.md) that Controller\Track\RegisterPriceWatch had no admin-side visibility at all.
 * Guarded by its own dedicated ACL resource (price_watch_subscriptions), matching every other
 * diagnostic grid in this module.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::price_watch_subscriptions';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::price_watch_subscriptions');
        $resultPage->getConfig()->getTitle()->prepend(__('Price Watch Subscriptions'));

        return $resultPage;
    }
}
