<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * "Browse Template Library" gallery page - see Model\Campaign\TemplateLibrary for the curated
 * templates it lists, and ImportFromLibrary.php (the POST handler each template's "Use this
 * Template" button submits to) for what happens on pick. Same GET-page/POST-handler split as
 * ImportForm.php/Import.php, just reading curated payloads instead of an uploaded file.
 */
class Library extends AbstractCampaignAction implements HttpGetActionInterface
{
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
        $resultPage->getConfig()->getTitle()->prepend(__('Campaign Template Library'));

        return $resultPage;
    }
}
