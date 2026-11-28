<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Customer360;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Ordo\Automation\Model\Customer360\Customer360Snapshot;
use Ordo\Automation\Model\Customer360\Customer360SnapshotBuilder;

class Index extends Template
{
    private ?Customer360Snapshot $snapshot = null;
    private bool $snapshotBuilt = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Customer360SnapshotBuilder $snapshotBuilder,
        private readonly PricingHelper $pricingHelper,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function formatTouchpointDate(string $rawDateTime): string
    {
        return $this->timezone->formatDateTime($rawDateTime, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
    }

    /**
     * Literal __('...') calls per case, not a dynamic __($variable) lookup against a label
     * array - same reasoning Model\Campaign\TypeLabels's own docblock gives: a dynamic call
     * resolves correctly at runtime but isn't discoverable by bin/magento i18n:collect-phrases
     * (phpcs's own Magento2.Legacy.Translate sniff flags it). The default arm's raw-type
     * fallback is passed through __() too, same as TypeLabels::humanize() - harmless, a CSV miss
     * just returns the key text unchanged.
     */
    public function getTouchpointTypeLabel(string $type): string
    {
        return match ($type) {
            'sent' => (string) __('Sent'),
            'opened' => (string) __('Opened'),
            'clicked' => (string) __('Clicked'),
            'read' => (string) __('Read'),
            'converted' => (string) __('Converted'),
            default => (string) __($type),
        };
    }

    public function formatCurrency(float $amount): string
    {
        return (string) $this->pricingHelper->currency($amount, true, false);
    }

    /**
     * ChurnRiskCalculator's own ratio (1.0 = exactly on the customer's historical cadence) -
     * two decimal places is enough precision for a human glancing at this screen.
     */
    public function formatRatio(float $ratio): string
    {
        return number_format($ratio, 2);
    }

    /**
     * Renders one "label: value" row of the stat-row markup repeated across this screen's cards.
     * $valueHtml is trusted, already-escaped markup - callers own escaping their own value.
     */
    public function statRow(string $label, string $valueHtml): string
    {
        return '<div class="ordo-trigger-stat-row">'
            . '<span class="ordo-trigger-stat-label">' . $this->escapeHtml($label) . '</span>'
            . $valueHtml
            . '</div>';
    }

    public function getCustomerEmail(): string
    {
        return (string) $this->registry->registry('ordo_customer_360_customer_email');
    }

    public function isCustomerResolved(): bool
    {
        return (int) $this->registry->registry('ordo_customer_360_customer_id') > 0;
    }

    public function getSnapshot(): ?Customer360Snapshot
    {
        if ($this->snapshotBuilt) {
            return $this->snapshot;
        }

        $this->snapshotBuilt = true;
        $customerId = (int) $this->registry->registry('ordo_customer_360_customer_id');

        if ($customerId > 0) {
            try {
                $this->snapshot = $this->snapshotBuilder->build($this->customerRepository->getById($customerId));
            } catch (NoSuchEntityException) {
                $this->snapshot = null;
            }
        }

        return $this->snapshot;
    }

    public function getSearchFormAction(): string
    {
        return $this->getUrl('*/*/index');
    }
}
