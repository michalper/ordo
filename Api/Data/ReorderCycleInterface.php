<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

interface ReorderCycleInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const SKU = 'sku';
    public const AVG_INTERVAL_DAYS = 'avg_interval_days';
    public const LAST_ORDER_DATE = 'last_order_date';
    public const NEXT_EXPECTED_DATE = 'next_expected_date';
    public const ORDERS_CONSIDERED = 'orders_considered';
    public const UPDATED_AT = 'updated_at';

    public function getEntityId(): ?int;

    public function getCustomerId(): int;

    public function getSku(): string;

    public function getAvgIntervalDays(): int;

    public function getLastOrderDate(): string;

    public function getNextExpectedDate(): string;

    public function getOrdersConsidered(): int;

    public function getUpdatedAt(): ?string;
}
