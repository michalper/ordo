<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Webapi;

use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Webapi\Rest\Response\FieldsFilter;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Ordo\Automation\Plugin\Webapi\SparseFieldsetPlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SparseFieldsetPluginTest extends TestCase
{
    /** @var ServiceOutputProcessor|MockObject */
    private $serviceOutputProcessor;

    /** @var FieldsFilter|MockObject */
    private $fieldsFilter;

    /** @var RestRequest|MockObject */
    private $request;

    private SparseFieldsetPlugin $plugin;

    protected function setUp(): void
    {
        $this->serviceOutputProcessor = $this->createStub(ServiceOutputProcessor::class);
        $this->fieldsFilter = $this->createMock(FieldsFilter::class);
        $this->request = $this->createStub(RestRequest::class);
        $this->plugin = new SparseFieldsetPlugin($this->fieldsFilter, $this->request);
    }

    public function testNonOrdoServiceClassIsUntouched(): void
    {
        $this->fieldsFilter->expects($this->never())->method('filter');
        $input = ['entity_id' => 1, 'name' => 'x'];

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            $input,
            [],
            \Magento\Catalog\Api\ProductRepositoryInterface::class,
            'getList'
        );

        $this->assertSame($input, $result);
    }

    public function testNonArrayResultIsUntouched(): void
    {
        $this->fieldsFilter->expects($this->never())->method('filter');

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            'not-an-array',
            [],
            \Ordo\Automation\Api\CampaignRepositoryInterface::class,
            'getList'
        );

        $this->assertSame('not-an-array', $result);
    }

    public function testNoFieldsParamReturnsUnfilteredResultEvenIfFilterYieldsEmptyArray(): void
    {
        $input = ['items' => [['entity_id' => 1, 'name' => 'Campaign']], 'total_count' => 1];
        $this->fieldsFilter->method('filter')->with($input)->willReturn([]);
        $this->request->method('getParam')->willReturn(null);

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            $input,
            [],
            \Ordo\Automation\Api\CampaignRepositoryInterface::class,
            'getList'
        );

        $this->assertSame($input, $result);
    }

    public function testUsableFieldsParamAppliesFilterEvenWhenResultBecomesEmpty(): void
    {
        $input = ['items' => [['entity_id' => 1, 'name' => 'Campaign']], 'total_count' => 1];
        $this->fieldsFilter->method('filter')->with($input)->willReturn([]);
        $this->request->method('getParam')->willReturn('nonexistent_field');

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            $input,
            [],
            \Ordo\Automation\Api\CampaignRepositoryInterface::class,
            'getList'
        );

        $this->assertSame([], $result);
    }

    public function testUsableFieldsParamAppliesFilterAndReturnsFilteredResult(): void
    {
        $input = ['items' => [['entity_id' => 1, 'name' => 'Campaign', 'enabled' => true]], 'total_count' => 1];
        $filtered = ['items' => [['entity_id' => 1, 'name' => 'Campaign']]];
        $this->fieldsFilter->method('filter')->with($input)->willReturn($filtered);
        $this->request->method('getParam')->willReturn('items[entity_id,name]');

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            $input,
            [],
            \Ordo\Automation\Api\CampaignRepositoryInterface::class,
            'getList'
        );

        $this->assertSame($filtered, $result);
    }

    public function testEmptyStringFieldsParamDoesNotCountAsUsable(): void
    {
        $input = ['items' => [['entity_id' => 1]]];
        $this->fieldsFilter->method('filter')->with($input)->willReturn([]);
        $this->request->method('getParam')->willReturn('');

        $result = $this->plugin->afterProcess(
            $this->serviceOutputProcessor,
            $input,
            [],
            \Ordo\Automation\Api\CampaignRepositoryInterface::class,
            'getList'
        );

        $this->assertSame($input, $result);
    }
}
