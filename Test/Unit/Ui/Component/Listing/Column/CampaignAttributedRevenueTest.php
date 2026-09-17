<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Model\Campaign\AttributionCalculator;
use Ordo\Automation\Ui\Component\Listing\Column\CampaignAttributedRevenue;
use PHPUnit\Framework\TestCase;

class CampaignAttributedRevenueTest extends TestCase
{
    public function testPrepareDataSourceReturnsUnchangedWithoutItems(): void
    {
        $column = $this->makeColumn($this->createStub(AttributionCalculator::class));

        self::assertSame([], $column->prepareDataSource([]));
    }

    public function testPrepareDataSourceAddsFormattedRevenuePerCampaign(): void
    {
        $attributionCalculator = $this->createMock(AttributionCalculator::class);
        $attributionCalculator->expects(self::once())
            ->method('getAttributedRevenueForCampaigns')
            ->with([5, 9])
            ->willReturn([
                5 => ['revenue' => 149.9, 'orders' => 2],
            ]);

        $column = $this->makeColumn($attributionCalculator);
        $column->setData('name', 'attributed_revenue');

        $dataSource = [
            'data' => [
                'items' => [
                    ['entity_id' => 5, 'name' => 'Win-back'],
                    ['entity_id' => 9, 'name' => 'Reorder'],
                ],
            ],
        ];

        $result = $column->prepareDataSource($dataSource);

        self::assertSame('149.90', $result['data']['items'][0]['attributed_revenue']);
        self::assertSame('0.00', $result['data']['items'][1]['attributed_revenue']);
    }

    private function makeColumn(AttributionCalculator $attributionCalculator): CampaignAttributedRevenue
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createStub(Processor::class));

        return new CampaignAttributedRevenue(
            $context,
            $this->createStub(UiComponentFactory::class),
            $attributionCalculator
        );
    }
}
