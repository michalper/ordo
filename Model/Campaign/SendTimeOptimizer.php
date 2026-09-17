<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\ResourceConnection;

/**
 * Computes a customer's historically best send hour (0-23, in THEIR OWN local timezone, resolved
 * via CustomerTimezoneResolver) from their own past email opens/clicks - a simple histogram over
 * ordo_message_log_event joined back to ordo_message_log, not a trained model (same "explainable
 * over statistically fancier" posture as Clv\ClvCalculator's own docblock: an admin can see "this
 * customer usually opens emails around 6pm their time" in the data itself, no black box).
 *
 * Email-only by construction: open/click events only exist for the email channel today (SendGrid
 * Event Webhook, see Controller\Email\StatusCallback) - sms/whatsapp/webhook/push sends never
 * write an ordo_message_log_event row, so this optimizer has no signal for them at all. Callers
 * outside the email send path would always get null back, which is exactly "don't optimize,
 * proceed with default behavior" - the same fail-soft posture as CustomerTimezoneResolver, just
 * for "no history" instead of "no timezone".
 */
class SendTimeOptimizer
{
    /**
     * Fewer events than this and a single "loudest" hour is likely noise (one customer who opened
     * two emails back to back looks like a strong 24-hour-a-day pattern with a sample of 2) - null
     * (don't optimize) is the safe default below this floor, never a low-confidence guess.
     */
    private const int MIN_SAMPLE_SIZE = 3;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerTimezoneResolver $customerTimezoneResolver
    ) {
    }

    /**
     * The local hour (0-23) with the most opens+clicks for this customer's past emails, or null
     * when there isn't enough data (fewer than MIN_SAMPLE_SIZE events total) to be confident. Ties
     * between hours are broken by the earliest hour, purely to make the result deterministic.
     */
    public function getBestHour(int $customerId, ?int $storeId = null): ?int
    {
        $eventTimestamps = $this->fetchEventTimestampsUtc($customerId);

        if (count($eventTimestamps) < self::MIN_SAMPLE_SIZE) {
            return null;
        }

        $timezone = $this->customerTimezoneResolver->resolve($customerId, $storeId);

        $histogram = array_fill(0, 24, 0);
        foreach ($eventTimestamps as $timestampUtc) {
            $dateTime = new \DateTimeImmutable($timestampUtc, new \DateTimeZone('UTC'));
            $localHour = (int) $dateTime->setTimezone($timezone)->format('G');
            $histogram[$localHour]++;
        }

        $bestHour = 0;
        $bestCount = $histogram[0];
        for ($hour = 1; $hour < 24; $hour++) {
            if ($histogram[$hour] > $bestCount) {
                $bestCount = $histogram[$hour];
                $bestHour = $hour;
            }
        }

        return $bestHour;
    }

    /**
     * @return string[] each event's created_at, as a UTC-formatted datetime string
     */
    private function fetchEventTimestampsUtc(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $eventTable = $this->resourceConnection->getTableName('ordo_message_log_event');
        $messageLogTable = $this->resourceConnection->getTableName('ordo_message_log');

        $rows = $connection->fetchCol(
            $connection->select()
                ->from(['e' => $eventTable], ['created_at'])
                ->join(
                    ['ml' => $messageLogTable],
                    'ml.entity_id = e.message_log_id',
                    []
                )
                ->where('ml.channel = ?', 'email')
                ->where('ml.customer_id = ?', $customerId)
        );

        return array_map('strval', $rows);
    }
}
