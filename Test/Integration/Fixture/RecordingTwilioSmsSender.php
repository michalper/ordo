<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Ordo\Automation\Model\Sms\SmsSenderInterface;

/**
 * Records (toPhone, message) instead of calling the real Twilio API — shared by
 * CampaignSendSmsActionTest and SendAbandonedCartFallbackRemindersTest, which used to carry an
 * identical copy each.
 */
class RecordingTwilioSmsSender implements SmsSenderInterface
{
    /** @var array<int, array{toPhone: string, message: string}> */
    public array $calls = [];

    public function send(string $toPhone, string $message): string
    {
        $this->calls[] = ['toPhone' => $toPhone, 'message' => $message];

        return 'SM_recorded_test_sid';
    }
}
