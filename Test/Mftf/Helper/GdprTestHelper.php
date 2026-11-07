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
        string $channel = 'email',
        string $dbHost = '127.0.0.1',
        string $dbName = 'magento',
        string $dbUser = 'root',
        string $dbPassword = ''
    ): void {
        $pdo = new \PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

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
        int $customerId,
        string $dbHost = '127.0.0.1',
        string $dbName = 'magento',
        string $dbUser = 'root',
        string $dbPassword = ''
    ): void {
        $pdo = new \PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

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
}
