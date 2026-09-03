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
    /**
     * @param int $customerId
     * @param string $tag
     * @return void
     */
    public function addTag(int $customerId, string $tag): void;

    /**
     * @param int $customerId
     * @param string $tag
     * @return void
     */
    public function removeTag(int $customerId, string $tag): void;

    /**
     * @param int $customerId
     * @param string $tag
     * @return bool
     */
    public function hasTag(int $customerId, string $tag): bool;

    /**
     * @param int $customerId
     * @return string[]
     */
    public function getTags(int $customerId): array;

    /**
     * @param string $tag
     * @return int[]
     */
    public function getCustomerIdsWithTag(string $tag): array;
}
