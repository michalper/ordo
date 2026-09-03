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

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @return int
     */
    public function getCustomerId(): int;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return int
     */
    public function getAvgIntervalDays(): int;

    /**
     * @return string
     */
    public function getLastOrderDate(): string;

    /**
     * @return string
     */
    public function getNextExpectedDate(): string;

    /**
     * @return int
     */
    public function getOrdersConsidered(): int;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
