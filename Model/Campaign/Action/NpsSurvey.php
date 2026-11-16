<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPromptFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"question": "..."}. Same queue-and-poll delivery shape as ShowPopup — queues a row in
 * ordo_survey_prompt that Controller\Track\Poll hands out the next time the target's browser
 * polls (view/frontend/web/js/tracker.js), since there is no synchronous way to push something
 * onto a page from inside a campaign dispatch.
 *
 * Targets whichever identifier the triggering context actually has: context["customer_id"] for
 * customer-only triggers, context["visitor_id"] for anonymous ones. At least one must be present,
 * or there is no browser to eventually deliver this to and the action is a no-op (logged, not
 * thrown — same fail-closed pattern as every other action here).
 *
 * Deliberately no frequency cap, unlike ShowPopup: a satisfaction survey is meant to be sent once
 * per triggering event (e.g. once per order), not throttled across campaigns the way a popup —
 * which risks feeling like repeat-spam — is.
 */
class NpsSurvey implements ActionInterface
{
    private const string ACTION_LABEL = 'nps_survey';

    public function __construct(
        private readonly SurveyPromptFactory $surveyPromptFactory,
        private readonly SurveyPromptResource $surveyPromptResource,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $resolved = $this->contextTargetResolver
            ->resolveTargetAndRequiredField($context, $params, 'question', self::ACTION_LABEL);
        if ($resolved === null) {
            return;
        }
        $target = $resolved['target'];
        $question = $resolved['value'];

        $prompt = $this->surveyPromptFactory->create();
        $prompt->setCustomerId($target->customerId);
        $prompt->setVisitorId($target->visitorId);
        $prompt->setQuestion($question);

        try {
            $this->surveyPromptResource->save($prompt);
        } catch (Throwable $e) {
            // Same fail-closed convention every other action in this module follows - a save
            // failure here must not propagate out of execute() and abort the rest of this
            // dispatch's own action chain (CampaignDispatcher::runOneAction() calls execute()
            // with no try/catch of its own).
            $this->logger->error(sprintf(
                'Ordo_Automation: campaign %s action failed to save: %s',
                self::ACTION_LABEL,
                $e->getMessage()
            ));
        }
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'question', 'label' => (string) __('Question')],
        ];
    }
}
