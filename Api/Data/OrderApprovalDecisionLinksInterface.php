<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * The approve/reject URLs for a pending approval — the only place this module ever exposes the
 * decision token over the API, and only behind OrderApprovalManagementInterface::
 * getDecisionLinksById(), an explicit, admin-ACL-protected action, not the general read API.
 * Lets a headless client (e.g. a sales-rep mobile app) act on a pending approval without
 * needing the original email.
 */
interface OrderApprovalDecisionLinksInterface
{
    /**
     * @return string
     */
    public function getApproveUrl(): string;

    /**
     * @return string
     */
    public function getRejectUrl(): string;
}
