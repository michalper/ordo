<?php
declare(strict_types=1);

namespace Ordo\Automation\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OrderApprovalInterface;

/**
 * Token-authenticated decision endpoints — mirrors the email approve/reject links
 * (Controller/Approval/{Approve,Reject}.php), which delegate to this same service. Possession
 * of the token is the credential, matching the original "no login required" email-link design,
 * so these routes are intentionally anonymous in webapi.xml, not admin-token protected.
 */
interface OrderApprovalManagementInterface
{
    /**
     * Releases the order into whatever status is normally the default for the "new" state.
     *
     * @return OrderApprovalInterface
     * @throws NoSuchEntityException if the token doesn't match a still-pending approval
     * @throws LocalizedException if the held order itself can no longer be found
     */
    public function approveByToken(string $token): OrderApprovalInterface;

    /**
     * Cancels the order (releasing reserved inventory).
     *
     * @return OrderApprovalInterface
     * @throws NoSuchEntityException if the token doesn't match a still-pending approval
     * @throws LocalizedException if the held order itself can no longer be found
     */
    public function rejectByToken(string $token): OrderApprovalInterface;
}
