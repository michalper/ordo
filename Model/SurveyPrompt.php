<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;

/**
 * A single-question 0-10 satisfaction/NPS prompt queued by a campaign's "nps_survey" action,
 * waiting to be claimed by Controller\Track\Poll on the target visitor/customer's next poll —
 * same claim-before-use pattern as PendingPopup. Unlike PendingPopup, the same row also holds
 * the eventual response (score/responded_at), set once by Controller\Track\SubmitSurveyResponse.
 */
class SurveyPrompt extends AbstractModel
{
    use VisitorIdentityFieldsTrait;

    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const VISITOR_ID = 'visitor_id';
    public const QUESTION = 'question';
    public const DELIVERED_AT = 'delivered_at';
    public const SCORE = 'score';
    public const RESPONDED_AT = 'responded_at';
    public const EXPIRES_AT = 'expires_at';
    public const QUESTION_TYPE = 'question_type';
    public const CHOICES = 'choices';
    public const RESPONSE_TEXT = 'response_text';

    protected function _construct(): void
    {
        $this->_init(SurveyPromptResource::class);
    }

    public function getQuestion(): string
    {
        return (string) $this->getData(self::QUESTION);
    }

    public function setQuestion(string $question): self
    {
        $this->setData(self::QUESTION, $question);
        return $this;
    }

    public function getDeliveredAt(): ?string
    {
        $value = $this->getData(self::DELIVERED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setDeliveredAt(?string $deliveredAt): self
    {
        $this->setData(self::DELIVERED_AT, $deliveredAt);
        return $this;
    }

    public function getScore(): ?int
    {
        $value = $this->getData(self::SCORE);
        return $value === null ? null : (int) $value;
    }

    public function setScore(?int $score): self
    {
        $this->setData(self::SCORE, $score);
        return $this;
    }

    public function getRespondedAt(): ?string
    {
        $value = $this->getData(self::RESPONDED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setRespondedAt(?string $respondedAt): self
    {
        $this->setData(self::RESPONDED_AT, $respondedAt);
        return $this;
    }

    public function setExpiresAt(?string $expiresAt): self
    {
        $this->setData(self::EXPIRES_AT, $expiresAt);
        return $this;
    }

    /**
     * "text" or "choice", set only by the generic_survey action - null means a legacy nps_survey
     * row (always a 0-10 score, see SCORE above), kept exactly as-is for backward compatibility.
     */
    public function getQuestionType(): ?string
    {
        $value = $this->getData(self::QUESTION_TYPE);
        return $value === null ? null : (string) $value;
    }

    public function setQuestionType(?string $questionType): self
    {
        $this->setData(self::QUESTION_TYPE, $questionType);
        return $this;
    }

    /**
     * Comma-separated list of options offered for a "choice" question_type - null for text/nps.
     */
    public function getChoices(): ?string
    {
        $value = $this->getData(self::CHOICES);
        return $value === null ? null : (string) $value;
    }

    public function setChoices(?string $choices): self
    {
        $this->setData(self::CHOICES, $choices);
        return $this;
    }

    /**
     * The free-text answer, or the chosen option's own text, for a text/choice question - never
     * set for a legacy nps question, which stores its answer in SCORE instead.
     */
    public function getResponseText(): ?string
    {
        $value = $this->getData(self::RESPONSE_TEXT);
        return $value === null ? null : (string) $value;
    }

    public function setResponseText(?string $responseText): self
    {
        $this->setData(self::RESPONSE_TEXT, $responseText);
        return $this;
    }
}
