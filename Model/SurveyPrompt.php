<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt as SurveyPromptResource;

/**
 * A single-question 0-10 satisfaction/NPS prompt queued by a campaign's "nps_survey" action,
 * waiting to be claimed by Controller\Track\Survey on the target visitor/customer's next poll —
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
}
