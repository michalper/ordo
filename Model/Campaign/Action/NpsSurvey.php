<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPromptFactory;
use Psr\Log\LoggerInterface;

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
    public function __construct(
        private readonly SurveyPromptFactory $surveyPromptFactory,
        private readonly SurveyPromptResource $surveyPromptResource,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $target = $this->contextTargetResolver->resolveCustomerOrVisitor($context);
        $question = trim((string) ($params['question'] ?? ''));

        if ($target->isEmpty()) {
            $this->logger->error(
                'Ordo_Automation: nps_survey action has no customer_id or visitor_id in context to target.'
            );
            return;
        }

        if ($question === '') {
            $this->logger->error('Ordo_Automation: nps_survey action is missing a question.');
            return;
        }

        $prompt = $this->surveyPromptFactory->create();
        $prompt->setCustomerId($target->customerId);
        $prompt->setVisitorId($target->visitorId);
        $prompt->setQuestion($question);

        $this->surveyPromptResource->save($prompt);
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'question', 'label' => (string) __('Question')],
        ];
    }
}
