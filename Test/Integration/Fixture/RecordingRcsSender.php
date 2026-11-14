<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Ordo\Automation\Model\Rcs\RcsSenderInterface;

/**
 * Records (toPhone, message) instead of calling the real Twilio RCS API - same role
 * RecordingTwilioSmsSender already plays for send_sms.
 */
class RecordingRcsSender implements RcsSenderInterface
{
    /** @var array<int, array{toPhone: string, message: string}> */
    public array $calls = [];

    public function send(string $toPhone, string $message): string
    {
        $this->calls[] = ['toPhone' => $toPhone, 'message' => $message];

        return 'RC_recorded_test_sid';
    }
}
