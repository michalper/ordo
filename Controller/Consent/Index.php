<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Consent;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Ordo\Automation\Controller\AbstractCustomerAccountAction;

/**
 * "Communication Preferences" — storefront customer-account page letting a logged-in customer
 * see and change their own channel/topic consent (Block\Frontend\ConsentPreferencesViewModel),
 * closing the "no storefront self-service preference page" gap
 * docs/adr/0026-per-topic-consent-granularity.md's own Consequences section named explicitly.
 */
class Index extends AbstractCustomerAccountAction implements HttpGetActionInterface
{
    public function execute()
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set((string) __('Communication Preferences'));

        return $resultPage;
    }
}
