<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\TrackerPoll;
use Ordo\Automation\Model\Track\TrackerPollReader;
use PHPUnit\Framework\TestCase;

/**
 * This resolver's only own job is resolving identity from the GraphQL customer-token context and
 * delegating to TrackerPollReader - see Test\Unit\Model\Track\TrackerPollReaderTest for the
 * actual popup/notification/survey business-logic coverage this used to duplicate.
 */
class TrackerPollTest extends TestCase
{
    private TrackerPollReader $trackerPollReader;

    protected function setUp(): void
    {
        $this->trackerPollReader = $this->createMock(TrackerPollReader::class);
    }

    private function resolve(?array $args, int $userId = 0): mixed
    {
        $resolver = new TrackerPoll($this->trackerPollReader, new CustomerIdResolver());

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

    public function testDelegatesToTheReaderWithTheGivenVisitorId(): void
    {
        $this->trackerPollReader->expects(self::once())
            ->method('resolve')
            ->with(null, 'v1')
            ->willReturn(['popup' => null]);

        self::assertSame(['popup' => null], $this->resolve(['visitorId' => 'v1']));
    }

    public function testDelegatesWithTheResolvedCustomerIdAndAnEmptyVisitorIdWhenMissing(): void
    {
        $this->trackerPollReader->expects(self::once())
            ->method('resolve')
            ->with(42, '')
            ->willReturn(['popup' => null]);

        self::assertSame(['popup' => null], $this->resolve(null, userId: 42));
    }
}
