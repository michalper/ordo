<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\Config\Source\AdAudiencePlatform;
use PHPUnit\Framework\TestCase;

class AdAudiencePlatformTest extends TestCase
{
    public function testToOptionArrayListsAllPlatforms(): void
    {
        $options = (new AdAudiencePlatform())->toOptionArray();

        $values = array_column($options, 'value');
        self::assertContains(AdAudience::PLATFORM_GOOGLE_ADS, $values);
        self::assertContains(AdAudience::PLATFORM_META, $values);
        self::assertContains(AdAudience::PLATFORM_TIKTOK, $values);
        self::assertCount(3, $options);
    }
}
