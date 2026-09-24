<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\LeadRoutingRule\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\LeadRoutingRule\Edit\BackButton;
use PHPUnit\Framework\TestCase;

class BackButtonTest extends TestCase
{
    public function testGetButtonDataReturnsBackConfig(): void
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://example.com/admin/ordo/leadroutingrule/');

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);

        $data = (new BackButton($context))->getButtonData();

        self::assertSame('Back', (string) $data['label']);
        self::assertSame('back', $data['class']);
        self::assertSame(10, $data['sort_order']);
    }
}
