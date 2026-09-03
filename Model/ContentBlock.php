<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * A reusable, admin-authored piece of email content — see etc/db_schema.xml's
 * ordo_content_block comment. Admin-only, plain AbstractModel, same shape as ScoreRule.
 */
class ContentBlock extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ContentBlockResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id === null ? null : (int) $id;
    }

    public function getIdentifier(): string
    {
        return (string) $this->getData('identifier');
    }

    public function setIdentifier(string $identifier): self
    {
        $this->setData('identifier', $identifier);
        return $this;
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

    public function getType(): string
    {
        return (string) $this->getData('type');
    }

    public function setType(string $type): self
    {
        $this->setData('type', $type);
        return $this;
    }

    public function getConfig(): ?string
    {
        $config = $this->getData('config');
        return $config === null ? null : (string) $config;
    }

    public function setConfig(?string $config): self
    {
        $this->setData('config', $config);
        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getData('enabled');
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData('enabled', $enabled);
        return $this;
    }

    /**
     * Decoded type-specific config, e.g. {"html": "..."} for a snippet or
     * {"feed_url": "...", "item_count": 5} for rss — never throws, an empty/invalid JSON
     * blob just resolves to an empty array.
     *
     * @return array<string, mixed>
     */
    public function getConfigArray(): array
    {
        $config = $this->getConfig();
        if ($config === null || $config === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfigArray(array $config): self
    {
        $this->setConfig((string) json_encode($config));
        return $this;
    }
}
