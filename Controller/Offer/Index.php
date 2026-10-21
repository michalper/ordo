<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Offer;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Ordo\Automation\Controller\AbstractCustomerAccountAction;

/**
 * "My Offers" — storefront customer-account page listing the logged-in customer's offers, each
 * with a self-service "Extend" action where Offer::canSelfExtend() allows it.
 */
class Index extends AbstractCustomerAccountAction implements HttpGetActionInterface
{
    public function execute()
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set((string) __('My Offers'));

        return $resultPage;
    }
}
