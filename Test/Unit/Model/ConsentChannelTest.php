<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConsentChannel;
use PHPUnit\Framework\TestCase;

class ConsentChannelTest extends TestCase
{
    /**
     * Regression for a full-repo /simplify finding: this exact "every channel except Ads" list
     * used to be hand-copied in 3 places (Controller\Adminhtml\Gdpr\Export, Block\Adminhtml\
     * Gdpr\Index, Block\Frontend\ConsentPreferencesViewModel) - precisely the stale-second-list
     * failure mode this enum's own class docblock describes happening once already with
     * ConsentManager::CHANNEL_* constants.
     */
    public function testDeliveryChannelsReturnsEveryChannelExceptAds(): void
    {
        self::assertSame(
            [ConsentChannel::Email, ConsentChannel::Sms, ConsentChannel::Push, ConsentChannel::WhatsApp, ConsentChannel::Rcs],
            ConsentChannel::deliveryChannels()
        );
    }

}
