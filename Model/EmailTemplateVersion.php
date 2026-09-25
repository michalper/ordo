<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion as EmailTemplateVersionResource;

/**
 * One snapshot of a Magento email_template row's content, taken by
 * Plugin\Email\SnapshotEmailTemplateVersion every time that template is saved. Plain data holder,
 * same convention as PushSubscription/ReferralCode (real getters/setters).
 */
class EmailTemplateVersion extends AbstractModel
{
    public const ENTITY_ID = 'entity_id';
    public const TEMPLATE_ID = 'template_id';
    public const TEMPLATE_CODE = 'template_code';
    public const TEMPLATE_SUBJECT = 'template_subject';
    public const TEMPLATE_TEXT = 'template_text';
    public const TEMPLATE_STYLES = 'template_styles';
    public const CREATED_AT = 'created_at';

    protected function _construct(): void
    {
        $this->_init(EmailTemplateVersionResource::class);
    }

    public function getTemplateId(): int
    {
        return (int) $this->getData(self::TEMPLATE_ID);
    }

    public function setTemplateId(int $templateId): self
    {
        $this->setData(self::TEMPLATE_ID, $templateId);
        return $this;
    }

    public function getTemplateCode(): string
    {
        return (string) $this->getData(self::TEMPLATE_CODE);
    }

    public function setTemplateCode(string $templateCode): self
    {
        $this->setData(self::TEMPLATE_CODE, $templateCode);
        return $this;
    }

    public function getTemplateSubject(): string
    {
        return (string) $this->getData(self::TEMPLATE_SUBJECT);
    }

    public function setTemplateSubject(string $templateSubject): self
    {
        $this->setData(self::TEMPLATE_SUBJECT, $templateSubject);
        return $this;
    }

    public function getTemplateText(): string
    {
        return (string) $this->getData(self::TEMPLATE_TEXT);
    }

    public function setTemplateText(string $templateText): self
    {
        $this->setData(self::TEMPLATE_TEXT, $templateText);
        return $this;
    }

    public function getTemplateStyles(): ?string
    {
        $value = $this->getData(self::TEMPLATE_STYLES);
        return $value === null ? null : (string) $value;
    }

    public function setTemplateStyles(?string $templateStyles): self
    {
        $this->setData(self::TEMPLATE_STYLES, $templateStyles);
        return $this;
    }
}
