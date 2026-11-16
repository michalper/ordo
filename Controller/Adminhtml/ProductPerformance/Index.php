<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductPerformance;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only product performance report (views/purchases/abandon rate per SKU) - same shape as
 * Controller\Adminhtml\Rfm\Index, including its own dedicated ACL resource.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::product_performance';

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
        return __('Product Performance Report');
    }
}
