<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;
use Ordo\Automation\Model\SurveyPromptFactory;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public, unauthenticated POST endpoint the frontend JS tracker calls when the visitor answers a
 * delivered survey prompt - the only way an ordo_survey_prompt row's score/response_text/
 * responded_at ever get set.
 *
 * Ownership is checked before recording a response (VisitorIdentityFieldsTrait::belongsTo()) - a
 * request can only answer a prompt that actually belongs to the requester's own
 * customer_id/visitor_id, same reasoning as Controller\Track\DismissNotification. A prompt
 * already answered is never overwritten (kept exactly once, silently rejected on a second
 * attempt).
 *
 * Which field gets recorded depends on the prompt's own question_type, not on which params the
 * request happened to send: a legacy nps_survey row (question_type null) keeps exactly the
 * original 0-10 "score" validation untouched (see docs/adr 0059 - a generic_survey feature must
 * not change existing NPS campaign behavior), while a text/choice row (set only by the
 * generic_survey action) instead validates and records "response_text" - a non-empty string
 * that, for a "choice" question, must be one of the prompt's own offered choices.
 */
class SubmitSurveyResponse extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SurveyPromptFactory $surveyPromptFactory,
        private readonly SurveyPromptResource $surveyPromptResource,
        private readonly VisitorIdentityResolver $visitorIdentityResolver
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $promptId = (int) $this->getRequest()->getParam('survey_id');

        if ($promptId <= 0) {
            return $result->setData(['ok' => false]);
        }

        $visitorId = $this->visitorIdentityResolver->resolveVisitorIdFromParam($this->getRequest());
        $customerId = $this->visitorIdentityResolver->resolveCustomerId();

        $prompt = $this->surveyPromptFactory->create();
        $this->surveyPromptResource->load($prompt, $promptId);

        if (!$prompt->getId()
            || $prompt->getRespondedAt() !== null
            || !$prompt->belongsTo($customerId, $visitorId)
        ) {
            return $result->setData(['ok' => false]);
        }

        if ($prompt->getQuestionType() === null) {
            // Legacy nps_survey row - unchanged 0-10 score validation.
            $score = $this->getRequest()->getParam('score');
            if (!is_numeric($score)) {
                return $result->setData(['ok' => false]);
            }

            $score = (int) $score;
            if ($score < 0 || $score > 10) {
                return $result->setData(['ok' => false]);
            }

            $prompt->setScore($score);
        } else {
            $responseText = trim((string) $this->getRequest()->getParam('response_text'));
            if ($responseText === '') {
                return $result->setData(['ok' => false]);
            }

            if ($prompt->getQuestionType() === 'choice') {
                $choices = array_map('trim', explode(',', (string) $prompt->getChoices()));
                if (!in_array($responseText, $choices, true)) {
                    return $result->setData(['ok' => false]);
                }
            }

            $prompt->setResponseText($responseText);
        }

        $prompt->setRespondedAt(date('Y-m-d H:i:s'));
        $this->surveyPromptResource->save($prompt);

        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
