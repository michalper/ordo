<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Backdates a real, already-responded ordo_survey_prompt row's responded_at past
 * Cron\PruneSurveyPrompts' 24-hour grace window, and confirms the row is genuinely gone
 * afterwards — no MFTF-reachable UI/API sets responded_at directly (it's set purely by
 * Controller\Track\SubmitSurveyResponse's own logic), same reasoning as NotificationTestHelper
 * for its own table.
 */
class SurveyPromptTestHelper extends Helper
{
    use DbConnectionTrait;

    public function backdateRespondedSurveyPromptForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'UPDATE ordo_survey_prompt SET responded_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) '
            . 'WHERE customer_id = :customer_id AND responded_at IS NOT NULL'
        );
        $statement->execute(['customer_id' => $customerId]);
    }

    /**
     * @throws \RuntimeException if a matching row still exists
     */
    public function assertNoSurveyPromptForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM ordo_survey_prompt WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_survey_prompt row still found for customer_id=%d.',
                $customerId
            ));
        }
    }
}
