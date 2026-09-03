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

    public function getEntityId(): ?int;

    public function getOrderId(): int;

    public function getAdminEmail(): string;

    public function getStatus(): string;

    public function getRemindersSent(): int;

    public function getCreatedAt(): ?string;

    public function getDecidedAt(): ?string;

    public function isPending(): bool;
}
