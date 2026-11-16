<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Customer360;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\CustomerLookupByEmailRegistrar;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Search-by-email admin page aggregating everything this module already knows about one
 * customer onto one screen - see docs/adr/0050.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::customer_360';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly CustomerLookupByEmailRegistrar $customerLookup
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::top_level';
    }

    protected function pageTitle(): Phrase
    {
        return __('Customer 360');
    }

    protected function beforeRender(): void
    {
        $email = trim((string) $this->getRequest()->getParam('email'));
        if ($email === '') {
            return;
        }

        if (!$this->customerLookup->resolveAndRegister($email, 'ordo_customer_360') instanceof CustomerInterface) {
            $this->messageManager->addErrorMessage(__('No customer found for that email.'));
        }
    }
}
