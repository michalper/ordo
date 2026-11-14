<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AdAudience;

/**
 * Wraps tempnam() behind an injectable class so TikTokSyncClient::uploadFile()'s "disk full /
 * no write permission" failure path can be exercised by a real test, the same "instance method,
 * not a bare global call" reasoning as every other hand-rolled primitive wrapper in this module
 * (Model\Push\BaseSixtyFourUrl, Model\Email\SendGridSignatureValidator).
 */
class TempFileFactory
{
    /**
     * @return string|false the new temp file's path, or false if it couldn't be created
     */
    public function create(): string|false
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        return tempnam(sys_get_temp_dir(), 'ordo_tiktok_audience_');
    }
}
