<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Gdpr;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\CustomerLookupByEmailRegistrar;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;
use Ordo\Automation\Controller\Adminhtml\SearchesCustomerByEmailTrait;

/**
 * Search-by-email admin page — the single entry point into this module's GDPR tooling
 * (consent view/toggle, data export, erasure). Resolving by email rather than a grid keeps this
 * deliberately minimal: this is a data-subject-request tool used rarely and one customer at a
 * time, not a bulk-management screen.
 */
class Index extends AbstractGdprAction implements HttpGetActionInterface
{
    use RendersGridPageTrait;
    use SearchesCustomerByEmailTrait {
        SearchesCustomerByEmailTrait::beforeRender insteadof RendersGridPageTrait;
    }

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
        return __('GDPR / Consent');
    }

    protected function customerSearchRegistryPrefix(): string
    {
        return 'ordo_gdpr';
    }
}
