<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Model\ResourceModel\Offer;

class OfferTest extends AbstractDbTestCase
{
    public function testInitializesWithOfferTableAndEntityIdField(): void
    {
        $resource = new Offer($this->makeDbContext());

        self::assertSame('ordo_offer', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }

    /**
     * Real quoteInto() behavior, close enough for asserting the exact WHERE clause
     * claimSelfExtension() builds - a plain sprintf-style '?' substitution.
     */
    private function stubQuoteInto(AdapterInterface&\PHPUnit\Framework\MockObject\MockObject $connection): void
    {
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', (string) $value, $text)
        );
    }

    public function testClaimSelfExtensionUpdatesModelWhenItsUpdateMatchesTheExpectedRow(): void
    {
        [$context, $connection] = $this->makeDbContextWithConnection();
        $this->stubQuoteInto($connection);
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_offer',
                ['expires_at' => '2026-12-01 00:00:00', 'extension_count' => 2],
                'entity_id = 7 AND extension_count = 1'
            )
            ->willReturn(1);

        $model = $this->createMock(OfferInterface::class);
        $model->method('getEntityId')->willReturn(7);
        $model->expects(self::once())->method('setExpiresAt')->with('2026-12-01 00:00:00');
        $model->expects(self::once())->method('setExtensionCount')->with(2);

        $resource = new Offer($context);

        self::assertTrue($resource->claimSelfExtension($model, '2026-12-01 00:00:00', 1));
    }

    /**
     * The lost-update race this method exists to close: another request's own claim already
     * moved extension_count past what this caller last read, so its own conditional UPDATE
     * matches zero rows - the model must be left exactly as the caller passed it in, not
     * mutated to a value that was never actually persisted.
     */
    public function testClaimSelfExtensionLeavesModelUntouchedWhenNoRowMatchesTheExpectedCount(): void
    {
        [$context, $connection] = $this->makeDbContextWithConnection();
        $this->stubQuoteInto($connection);
        $connection->method('update')->willReturn(0);

        $model = $this->createMock(OfferInterface::class);
        $model->method('getEntityId')->willReturn(7);
        $model->expects(self::never())->method('setExpiresAt');
        $model->expects(self::never())->method('setExtensionCount');

        $resource = new Offer($context);

        self::assertFalse($resource->claimSelfExtension($model, '2026-12-01 00:00:00', 1));
    }
}
