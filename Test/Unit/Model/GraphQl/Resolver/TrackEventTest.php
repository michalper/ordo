<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\TrackEvent;
use Ordo\Automation\Model\VisitorEventLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class TrackEventTest extends TestCase
{
    private VisitorEventLogger $visitorEventLogger;
    private Config $config;

    protected function setUp(): void
    {
        $this->visitorEventLogger = $this->createMock(VisitorEventLogger::class);
        $this->config = $this->createStub(Config::class);
    }

    private function makeResolver(): TrackEvent
    {
        return new TrackEvent($this->visitorEventLogger, new CustomerIdResolver(), $this->config);
    }

    private function resolve(TrackEvent $resolver, array $args, int $userId = 0): mixed
    {
        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsDisabledWhenTrackingOff(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(false);

        $this->visitorEventLogger->expects(self::never())->method('log');

        self::assertSame(
            ['ok' => false, 'reason' => 'tracking_disabled'],
            $this->resolve($resolver, ['visitorId' => 'v1', 'eventType' => 'page_view'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsInvalidPayloadWhenVisitorIdMissing(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(true);

        $this->visitorEventLogger->expects(self::never())->method('log');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve($resolver, ['visitorId' => '', 'eventType' => 'page_view'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsInvalidPayloadWhenEventTypeNotAllowed(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(true);

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve($resolver, ['visitorId' => 'v1', 'eventType' => 'not_allowed'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogsEventForAnonymousVisitor(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(true);

        $this->visitorEventLogger->expects(self::once())->method('log')->with('v1', 'product_view', '15', null);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['visitorId' => 'v1', 'eventType' => 'product_view', 'eventKey' => '15'])
        );
    }

    /**
     * checkout_start (docs/adr/0031) needs no event_key, unlike product_view/category_view -
     * there's nothing to identify about "checkout started" beyond the fact itself.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testLogsCheckoutStartEventWithNoKey(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(true);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'checkout_start', null, null);

        self::assertSame(
            ['ok' => true],
            $this->resolve($resolver, ['visitorId' => 'v1', 'eventType' => 'checkout_start'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogsEventForLoggedInCustomerAndTruncatesTheKey(): void
    {
        $resolver = $this->makeResolver();
        $this->config->method('isTrackingEnabled')->willReturn(true);

        $this->visitorEventLogger->expects(self::once())->method('log')
            ->with('v1', 'category_view', str_repeat('x', 255), 42);

        $this->resolve(
            $resolver,
            ['visitorId' => 'v1', 'eventType' => 'category_view', 'eventKey' => str_repeat('x', 300)],
            userId: 42
        );
    }
}
