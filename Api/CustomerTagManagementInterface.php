<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

/**
 * Behavioral customer tagging — the segmentation primitive every trigger/condition in this
 * module reads or writes. Exposed as its own service contract (rather than folded into
 * CustomerRepositoryInterface) so a headless storefront/CRM can add, remove, and query tags
 * without going through the admin UI.
 */
interface CustomerTagManagementInterface
{
    public function addTag(int $customerId, string $tag): void;

    public function removeTag(int $customerId, string $tag): void;

    public function hasTag(int $customerId, string $tag): bool;

    /**
     * @return string[]
     */
    public function getTags(int $customerId): array;

    /**
     * @return int[]
     */
    public function getCustomerIdsWithTag(string $tag): array;
}
