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

    /**
     * Whether the single merged /ordo/track/poll request (Controller\Track\Poll, see ADR 0024)
     * should run at all - true the moment any one of popup/notification/NPS survey is enabled,
     * since all three now share one request regardless of which individual concern(s) are on.
     */
    public function isPollEnabled(): bool
    {
        return $this->config->isPopupEnabled()
            || $this->config->isNotificationEnabled()
            || $this->config->isNpsSurveyEnabled();
    }

    /**
     * The three features used to each have their own configurable poll interval (15/20/25s
     * defaults) driving three separate timers. Merged into one request, there is only one timer
     * left to configure - the fastest of whichever concerns are actually enabled, so no enabled
     * feature polls less often than its own admin-configured interval asked for. Polling a
     * disabled (or more patient) feature's data slightly more often than strictly necessary is
     * free, since it now rides the same single request either way.
     */
    public function getPollIntervalSeconds(): int
    {
        $intervals = [];
        if ($this->config->isPopupEnabled()) {
            $intervals[] = $this->config->getPopupPollIntervalSeconds();
        }
        if ($this->config->isNotificationEnabled()) {
            $intervals[] = $this->config->getNotificationPollIntervalSeconds();
        }
        if ($this->config->isNpsSurveyEnabled()) {
            $intervals[] = $this->config->getNpsSurveyPollIntervalSeconds();
        }

        return $intervals === [] ? $this->config->getPopupPollIntervalSeconds() : min($intervals);
    }

    public function isPushEnabled(): bool
    {
        return $this->config->isPushEnabled();
    }

    public function isGtmBridgeEnabled(): bool
    {
        return $this->config->isGtmBridgeEnabled();
    }

    public function isExitIntentEnabled(): bool
    {
        return $this->config->isExitIntentEnabled();
    }

    /**
     * Not a secret - safe to render straight into the page, same as any other applicationServerKey
     * example in the Web Push spec itself.
     */
    public function getVapidPublicKey(): string
    {
        return $this->config->getVapidPublicKey();
    }
}
