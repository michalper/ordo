<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ordo\Automation\Model\AdAudience;

/**
 * The platform options for an ad audience — wraps AdAudience's own PLATFORM_* constants so the
 * admin grid/form select and Model\AdAudience\SyncClientPool's registered keys can never drift
 * apart.
 */
class AdAudiencePlatform implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AdAudience::PLATFORM_GOOGLE_ADS, 'label' => __('Google Ads')],
            ['value' => AdAudience::PLATFORM_META, 'label' => __('Meta')],
            ['value' => AdAudience::PLATFORM_TIKTOK, 'label' => __('TikTok')],
        ];
    }
}
