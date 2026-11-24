<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Confirms Controller\Adminhtml\Gdpr\Erase's real, database-observable effect — no MFTF-reachable
 * assertion on the admin page itself proves a *deletion* happened (the page just redirects back
 * to an empty search form either way), so this reads ordo_customer_consent directly, same
 * reasoning as NotificationTestHelper/SurveyPromptTestHelper for their own tables.
 */
class GdprTestHelper extends Helper
{
    use DbConnectionTrait;

    /**
     * Per ADR 0027, the Promotional consent topic now defaults to NOT consented for a customer
     * with no explicit ordo_customer_consent_topic row - every MFTF scenario whose campaign
     * relies on a send_email/send_sms/send_whatsapp/send_push action firing (no explicit 'topic'
     * param, so it defaults to Promotional) needs this called on its freshly-created test
     * customer, or the send is silently skipped and the scenario times out waiting on MailHog/a
     * recorded send that was never attempted.
     */
    public function grantPromotionalConsent(
        int $customerId,
        string $channel = 'email'
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'INSERT INTO ordo_customer_consent_topic (customer_id, channel, topic, consented, source) '
            . 'VALUES (:customer_id, :channel, :topic, 1, :source) '
            . 'ON DUPLICATE KEY UPDATE consented = 1'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'channel' => $channel,
            'topic' => 'promotional',
            'source' => 'mftf-test-fixture',
        ]);
    }

    public function assertNoConsentRowForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM ordo_customer_consent WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_customer_consent row still found for customer_id=%d.',
                $customerId
            ));
        }
    }

    /**
     * Confirms Controller\Track\CaptureChannelConsent's real, database-observable effect - the
     * endpoint itself only ever returns {"ok": true/false}, never the row it wrote, so this reads
     * ordo_customer_consent directly, same reasoning as this helper's own assertNoConsentRowForCustomer().
     *
     * $consented is '1'/'0' (a plain string, not bool) - every MFTF <argument> value is passed
     * as a string, and this file is declare(strict_types=1), so a bool-typed parameter would
     * throw a TypeError the moment MFTF's own XML (which has no boolean literal, only text
     * content) tried to call this with "true"/"false".
     */
    public function assertConsentRow(
        int $customerId,
        string $channel,
        string $consented
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT consented FROM ordo_customer_consent WHERE customer_id = :customer_id AND channel = :channel'
        );
        $statement->execute(['customer_id' => $customerId, 'channel' => $channel]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException(sprintf(
                'No ordo_customer_consent row found for customer_id=%d, channel=%s.',
                $customerId,
                $channel
            ));
        }

        $expectedConsented = $consented === '1';
        $actualConsented = (bool) $row['consented'];
        if ($actualConsented !== $expectedConsented) {
            throw new \RuntimeException(sprintf(
                'ordo_customer_consent row for customer_id=%d, channel=%s has consented=%s, expected %s.',
                $customerId,
                $channel,
                $actualConsented ? 'true' : 'false',
                $expectedConsented ? 'true' : 'false'
            ));
        }
    }
}
