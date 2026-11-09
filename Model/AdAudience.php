<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;

/**
 * A segment configured to sync as a remarketing audience to an external ad platform — see
 * etc/db_schema.xml's ordo_ad_audience comment. Admin-only, a named, toggleable top-level
 * entity like Segment.
 */
class AdAudience extends AbstractNamedToggleableEntityModel
{
    public const string PLATFORM_GOOGLE_ADS = 'google_ads';
    public const string PLATFORM_META = 'meta';
    public const string PLATFORM_TIKTOK = 'tiktok';

    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';

    protected function _construct(): void
    {
        $this->_init(AdAudienceResource::class);
    }

    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
    }

    public function getSegmentId(): int
    {
        return (int) $this->getData('segment_id');
    }

    public function setSegmentId(int $segmentId): self
    {
        $this->setData('segment_id', $segmentId);
        return $this;
    }

    public function getPlatform(): string
    {
        return (string) $this->getData('platform');
    }

    public function setPlatform(string $platform): self
    {
        $this->setData('platform', $platform);
        return $this;
    }

    public function getExternalAudienceId(): ?string
    {
        $value = $this->getData('external_audience_id');
        return $value === null ? null : (string) $value;
    }

    public function setExternalAudienceId(?string $externalAudienceId): self
    {
        $this->setData('external_audience_id', $externalAudienceId);
        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->setData('enabled', $enabled);
        return $this;
    }

    public function getLastSyncedAt(): ?string
    {
        $value = $this->getData('last_synced_at');
        return $value === null ? null : (string) $value;
    }

    public function setLastSyncedAt(?string $lastSyncedAt): self
    {
        $this->setData('last_synced_at', $lastSyncedAt);
        return $this;
    }

    public function getLastSyncStatus(): ?string
    {
        $value = $this->getData('last_sync_status');
        return $value === null ? null : (string) $value;
    }

    public function setLastSyncStatus(?string $lastSyncStatus): self
    {
        $this->setData('last_sync_status', $lastSyncStatus);
        return $this;
    }

    public function getLastSyncMessage(): ?string
    {
        $value = $this->getData('last_sync_message');
        return $value === null ? null : (string) $value;
    }

    public function setLastSyncMessage(?string $lastSyncMessage): self
    {
        $this->setData('last_sync_message', $lastSyncMessage);
        return $this;
    }
}
