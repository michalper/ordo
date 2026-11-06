<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate as WhatsAppTemplateResource;

/**
 * A WhatsApp message template awaiting/holding Meta's own approval - see etc/db_schema.xml's
 * ordo_whatsapp_template comment for why this lifecycle exists at all. Admin-only, plain
 * AbstractModel, same shape as AdAudience/ScoreRule.
 */
class WhatsAppTemplate extends AbstractModel
{
    use HasEntityIdGetterTrait;

    public const string CATEGORY_MARKETING = 'marketing';
    public const string CATEGORY_UTILITY = 'utility';
    public const string CATEGORY_AUTHENTICATION = 'authentication';

    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_APPROVED = 'approved';
    public const string STATUS_REJECTED = 'rejected';
    public const string STATUS_DISABLED = 'disabled';

    protected function _construct(): void
    {
        $this->_init(WhatsAppTemplateResource::class);
    }

    public function getName(): string
    {
        return (string) $this->getData('name');
    }

    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
    }

    public function getMetaTemplateName(): string
    {
        return (string) $this->getData('meta_template_name');
    }

    public function setMetaTemplateName(string $metaTemplateName): self
    {
        $this->setData('meta_template_name', $metaTemplateName);
        return $this;
    }

    public function getCategory(): string
    {
        return (string) $this->getData('category');
    }

    public function setCategory(string $category): self
    {
        $this->setData('category', $category);
        return $this;
    }

    public function getLanguage(): string
    {
        return (string) $this->getData('language');
    }

    public function setLanguage(string $language): self
    {
        $this->setData('language', $language);
        return $this;
    }

    public function getBodyText(): string
    {
        return (string) $this->getData('body_text');
    }

    public function setBodyText(string $bodyText): self
    {
        $this->setData('body_text', $bodyText);
        return $this;
    }

    public function getPlaceholderLegend(): ?string
    {
        $value = $this->getData('placeholder_legend');
        return $value === null ? null : (string) $value;
    }

    public function setPlaceholderLegend(?string $placeholderLegend): self
    {
        $this->setData('placeholder_legend', $placeholderLegend);
        return $this;
    }

    public function getMetaTemplateId(): ?string
    {
        $value = $this->getData('meta_template_id');
        return $value === null ? null : (string) $value;
    }

    public function setMetaTemplateId(?string $metaTemplateId): self
    {
        $this->setData('meta_template_id', $metaTemplateId);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function setStatus(string $status): self
    {
        $this->setData('status', $status);
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->getStatus() === self::STATUS_APPROVED;
    }

    public function getRejectionReason(): ?string
    {
        $value = $this->getData('rejection_reason');
        return $value === null ? null : (string) $value;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->setData('rejection_reason', $rejectionReason);
        return $this;
    }

    public function getSubmittedAt(): ?string
    {
        $value = $this->getData('submitted_at');
        return $value === null ? null : (string) $value;
    }

    public function setSubmittedAt(?string $submittedAt): self
    {
        $this->setData('submitted_at', $submittedAt);
        return $this;
    }
}
