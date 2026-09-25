<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Customer360;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;

/**
 * Search-by-email admin page aggregating everything this module already knows about one
 * customer - segments, tags, RFM/CLV, lead score, NPS, loyalty tier, order history - onto one
 * screen instead of the five separate ones a merchant previously had to cross-reference by hand.
 * Same search-then-display shape as Controller\Adminhtml\Gdpr\Index, deliberately: this is a
 * look-up tool used one customer at a time, not a bulk-management grid.
 */
class Index extends AbstractCustomer360Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $email = trim((string) $this->getRequest()->getParam('email'));

        if ($email !== '') {
            try {
                $customer = $this->customerRepository->get($email);
                $this->registry->register('ordo_customer_360_customer_id', (int) $customer->getId());
                $this->registry->register('ordo_customer_360_customer_email', $customer->getEmail());
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('No customer found for that email.'));
            }
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::top_level');
        $resultPage->getConfig()->getTitle()->prepend(__('Customer 360'));

        return $resultPage;
    }
}
