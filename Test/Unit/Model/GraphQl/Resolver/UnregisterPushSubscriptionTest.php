<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\UnregisterPushSubscription;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class UnregisterPushSubscriptionTest extends TestCase
{
    private PushSubscriptionManager $pushSubscriptionManager;

    protected function setUp(): void
    {
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
    }

    private function resolve(array $args, int $userId = 0): mixed
    {
        $resolver = new UnregisterPushSubscription($this->pushSubscriptionManager, new CustomerIdResolver());

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
    public function testReturnsFalseForAnEmptyEndpoint(): void
    {
        $this->pushSubscriptionManager->expects(self::never())->method('unregister');

        self::assertSame(['ok' => false], $this->resolve(['endpoint' => '']));
    }

    public function testUnregistersForAVisitor(): void
    {
        $this->pushSubscriptionManager->expects(self::once())->method('unregister')
            ->with('https://push.example.test/x', null, 'v1');

        self::assertSame(
            ['ok' => true],
            $this->resolve(['endpoint' => 'https://push.example.test/x', 'visitorId' => 'v1'])
        );
    }

    public function testUnregistersForALoggedInCustomer(): void
    {
        $this->pushSubscriptionManager->expects(self::once())->method('unregister')
            ->with('https://push.example.test/x', 42, '');

        self::assertSame(
            ['ok' => true],
            $this->resolve(['endpoint' => 'https://push.example.test/x'], userId: 42)
        );
    }
}
