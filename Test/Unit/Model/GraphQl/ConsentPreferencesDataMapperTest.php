<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl;

use Ordo\Automation\Model\ConsentChannelPreference;
use Ordo\Automation\Model\ConsentPreferences;
use Ordo\Automation\Model\GraphQl\ConsentPreferencesDataMapper;
use PHPUnit\Framework\TestCase;

class ConsentPreferencesDataMapperTest extends TestCase
{
    private function makeChannel(bool $consented, bool $promotional, bool $accountUpdates): ConsentChannelPreference
    {
        return (new ConsentChannelPreference())
            ->setConsented($consented)
            ->setPromotional($promotional)
            ->setAccountUpdates($accountUpdates);
    }

    public function testMapsEveryChannelAndAdsOntoTheGraphQlShape(): void
    {
        $preferences = (new ConsentPreferences())
            ->setEmail($this->makeChannel(true, false, true))
            ->setSms($this->makeChannel(false, false, true))
            ->setPush($this->makeChannel(true, true, true))
            ->setWhatsapp($this->makeChannel(true, false, false))
            ->setRcs($this->makeChannel(false, false, false))
            ->setAds(true);

        self::assertSame(
            [
                'email' => ['consented' => true, 'promotional' => false, 'account_updates' => true],
                'sms' => ['consented' => false, 'promotional' => false, 'account_updates' => true],
                'push' => ['consented' => true, 'promotional' => true, 'account_updates' => true],
                'whatsapp' => ['consented' => true, 'promotional' => false, 'account_updates' => false],
                'rcs' => ['consented' => false, 'promotional' => false, 'account_updates' => false],
                'ads' => true,
            ],
            (new ConsentPreferencesDataMapper())->map($preferences)
        );
    }
}
