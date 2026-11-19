<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Inserts a real ordo_offer row directly via SQL — Offer is a REST-only entity (`/V1/ordo/offers`,
 * see Api/OfferRepositoryInterface), no admin CRUD UI exists for it at all (confirmed via
 * SCENARIOS.md's own scope check), so there is no MFTF-reachable UI action to create one through.
 * Same out-of-band PDO pattern as OrderBackdateHelper/CronScheduleHelper for their own tables, via
 * DbConnectionTrait.
 */
class OfferTestHelper extends Helper
{
    use DbConnectionTrait;

    public function insertOffer(
        int $customerId,
        string $reference,
        string $status,
        string $expiresAt,
        int $extensionCount = 0,
        string $total = '100.0000'
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'INSERT INTO ordo_offer '
            . '(customer_id, reference, status, total, currency_code, expires_at, extension_count) '
            . 'VALUES (:customer_id, :reference, :status, :total, \'USD\', :expires_at, :extension_count)'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'reference' => $reference,
            'status' => $status,
            'total' => $total,
            'expires_at' => $expiresAt,
            'extension_count' => $extensionCount,
        ]);
    }

    /**
     * Same as insertOffer(), but computes expires_at server-side as CURDATE() + $daysFromToday
     * (negative for a past date) - needed for Cron\SendOfferExpiryReminders/ExpireOverdueOffers,
     * whose own filters (Model/ResourceModel/Offer/Collection.php's addExpiringOnFilter/
     * addPastExpiryFilter) compare against literally today's date at cron-run time, which an
     * MFTF test authored once can't hardcode a real calendar date for.
     */
    public function insertOfferWithRelativeExpiry(
        int $customerId,
        string $reference,
        string $status,
        int $daysFromToday,
        int $extensionCount = 0,
        string $total = '100.0000'
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'INSERT INTO ordo_offer '
            . '(customer_id, reference, status, total, currency_code, expires_at, extension_count) '
            . 'VALUES (:customer_id, :reference, :status, :total, \'USD\', '
            . 'DATE_ADD(CURDATE(), INTERVAL :days_from_today DAY), :extension_count)'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'reference' => $reference,
            'status' => $status,
            'total' => $total,
            'days_from_today' => $daysFromToday,
            'extension_count' => $extensionCount,
        ]);
    }

    /**
     * @throws \RuntimeException if no matching row exists
     */
    public function assertOfferStatus(
        string $reference,
        string $expectedStatus
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare('SELECT status FROM ordo_offer WHERE reference = :reference');
        $statement->execute(['reference' => $reference]);
        $status = $statement->fetchColumn();

        if ($status === false) {
            throw new \RuntimeException(sprintf('No ordo_offer row found for reference="%s".', $reference));
        }

        if ($status !== $expectedStatus) {
            throw new \RuntimeException(sprintf(
                'ordo_offer reference="%s" has status="%s", expected "%s".',
                $reference,
                $status,
                $expectedStatus
            ));
        }
    }
}
