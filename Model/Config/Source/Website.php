<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every real website, for the "which website(s) does this apply to" multiselect shared by the
 * campaign/segment/score-rule website-scoping forms (see docs/adr/0014). Website-level, not
 * store-view-level like Magento core's own CMS block/page "Store View" multiselect
 * (Magento\Cms\Ui\Component\Listing\Column\Cms\Options) — this module's own scoping concept is
 * "which website(s)" throughout (ADR 0013's CustomerStoreResolver resolves a customer's STORE,
 * but a customer's own getWebsiteId() is the thing every one of these condition checks actually
 * compares against), so a plain website list, not a store-view tree grouped by website, is what
 * the option list needs to be.
 */
class Website implements OptionSourceInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $options[] = [
                'value' => (int) $website->getId(),
                'label' => $website->getName(),
            ];
        }

        return $options;
    }
}
