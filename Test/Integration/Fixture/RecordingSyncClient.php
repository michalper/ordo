<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Ordo\Automation\Api\AdAudience\SyncClientInterface;

/**
 * Records (externalAudienceId, hashedEmails) instead of calling a real ad platform - test-only
 * helper, same role RecordingTwilioSmsSender plays for the SMS tests.
 */
class RecordingSyncClient implements SyncClientInterface
{
    /** @var array<int, array{externalAudienceId: ?string, hashedEmails: array<int, string>}> */
    public array $calls = [];

    public function sync(?string $externalAudienceId, array $hashedEmails): ?string
    {
        $this->calls[] = ['externalAudienceId' => $externalAudienceId, 'hashedEmails' => $hashedEmails];

        return null;
    }
}
