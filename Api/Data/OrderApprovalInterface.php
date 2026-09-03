<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface OrderApprovalInterface
{
    public const ENTITY_ID = 'entity_id';
    public const ORDER_ID = 'order_id';
    public const ADMIN_EMAIL = 'admin_email';
    public const STATUS = 'status';
    public const REMINDERS_SENT = 'reminders_sent';
    public const CREATED_AT = 'created_at';
    public const DECIDED_AT = 'decided_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @return string
     */
    public function getAdminEmail(): string;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @return int
     */
    public function getRemindersSent(): int;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getDecidedAt(): ?string;

    /**
     * @return bool
     */
    public function isPending(): bool;
}
