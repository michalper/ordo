<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Frontend;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Helper\Config;

class TrackerViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function isTrackingEnabled(): bool
    {
        return $this->config->isTrackingEnabled();
    }

    public function isPopupEnabled(): bool
    {
        return $this->config->isPopupEnabled();
    }

    public function getPopupPollIntervalSeconds(): int
    {
        return $this->config->getPopupPollIntervalSeconds();
    }
}
