<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\OrderApprovalInterface;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

class OrderApproval extends AbstractModel implements OrderApprovalInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected function _construct(): void
    {
        $this->_init(OrderApprovalResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id === null ? null : (int) $id;
    }

    public function getOrderId(): int
    {
        return (int) $this->getData(self::ORDER_ID);
    }

    public function getAdminEmail(): string
    {
        return (string) $this->getData(self::ADMIN_EMAIL);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function getRemindersSent(): int
    {
        return (int) $this->getData(self::REMINDERS_SENT);
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    public function getDecidedAt(): ?string
    {
        return $this->getData(self::DECIDED_AT);
    }

    public function isPending(): bool
    {
        return $this->getData('status') === self::STATUS_PENDING;
    }

    /**
     * Deliberately NOT part of Api\Data\OrderApprovalInterface — the token is a bearer secret
     * (possession of it approves/rejects the order, no other auth), so it must never round-trip
     * through the general read API. Only OrderApprovalManagement::getDecisionLinksById() reads
     * this, behind its own explicit, admin-ACL-protected action.
     */
    public function getToken(): string
    {
        return (string) $this->getData('token');
    }
}
