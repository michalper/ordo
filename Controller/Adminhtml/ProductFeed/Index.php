<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only health grid over ordo_product_feed_run_log - "did the shopping feed actually
 * generate successfully for each store, and when" is no longer invisible without a raw DB query
 * (the ROADMAP.md gap this closes, alongside multi-store support in
 * Model\ProductFeed\GoogleMerchantFeedGenerator).
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::campaigns';
    }

    protected function pageTitle(): Phrase
    {
        return __('Product Feed Health');
    }
}
