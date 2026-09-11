<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\TemplateTestSend;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * "Test Send" admin page - see Send.php's own docblock for what it actually does and why it
 * doesn't reuse the campaign Send* actions as-is.
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
        $resultPage->getConfig()->getTitle()->prepend(__('Template Test Send'));

        return $resultPage;
    }
}
