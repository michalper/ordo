<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\Sms\MessageLogWriter;
use Psr\Log\LoggerInterface;

/**
 * The "check the cap, and if it's reached, log + record it as suppressed" sequence every
 * Send* campaign action needs was identical 4 times over (SendEmail/SendSms/SendWhatsApp/
 * SendPush each repeating the same 6 lines around FrequencyCapManager::hasCapacity()) - flagged
 * as real duplication, not just a style nitpick, since a future change to that sequence (e.g. a
 * different log level, an extra field on the suppressed row) would otherwise have to be applied
 * in 4 places identically or drift. This is that sequence, centralized once.
 */
class FrequencyCapGate
{
    public function __construct(
        private readonly FrequencyCapManager $frequencyCapManager,
        private readonly MessageLogWriter $messageLogWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return bool true if the send may proceed, false if it was suppressed (already logged and
     *     recorded - the caller just needs to stop, not log/record anything itself)
     */
    public function allows(
        int $customerId,
        string $channel,
        string $toAddress,
        string $actionName,
        ?int $campaignId = null,
        ?string $variant = null
    ): bool {
        if ($this->frequencyCapManager->hasCapacity($customerId)) {
            return true;
        }

        $this->logger->info(sprintf(
            'Ordo_Automation: %s action skipped for customer #%d, frequency cap reached.',
            $actionName,
            $customerId
        ));
        $this->messageLogWriter->recordSuppressed($channel, $customerId, $toAddress, $campaignId, $variant);

        return false;
    }
}
