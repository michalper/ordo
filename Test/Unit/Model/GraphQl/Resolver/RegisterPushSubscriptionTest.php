<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\RegisterPushSubscription;
use Ordo\Automation\Model\Push\PushEndpointValidator;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RegisterPushSubscriptionTest extends TestCase
{
    private PushSubscriptionManager $pushSubscriptionManager;
    private PushEndpointValidator $pushEndpointValidator;
    private Config $config;

    protected function setUp(): void
    {
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(true);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPushEnabled')->willReturn(true);
    }

    private function resolve(array $args, int $userId = 0): mixed
    {
        $resolver = new RegisterPushSubscription(
            $this->pushSubscriptionManager,
            $this->pushEndpointValidator,
            new CustomerIdResolver(),
            $this->config
        );

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
    public function testReturnsFalseWhenPushDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPushEnabled')->willReturn(false);

        $this->pushSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'push_disabled'],
            $this->resolve(['endpoint' => 'https://push.example.test/x', 'p256dh' => 'k', 'auth' => 'a', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseForMissingFields(): void
    {
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve(['endpoint' => '', 'p256dh' => 'k', 'auth' => 'a', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenNeitherCustomerNorVisitorIdentityGiven(): void
    {
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve(['endpoint' => 'https://push.example.test/x', 'p256dh' => 'k', 'auth' => 'a'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseForADisallowedEndpoint(): void
    {
        $this->pushEndpointValidator = $this->createStub(PushEndpointValidator::class);
        $this->pushEndpointValidator->method('isAllowed')->willReturn(false);
        $this->pushSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_endpoint'],
            $this->resolve(['endpoint' => 'http://169.254.169.254/', 'p256dh' => 'k', 'auth' => 'a', 'visitorId' => 'v1'])
        );
    }

    public function testRegistersForAVisitor(): void
    {
        $this->pushSubscriptionManager->expects(self::once())->method('register')
            ->with('https://push.example.test/x', 'k', 'a', null, 'v1');

        self::assertSame(
            ['ok' => true],
            $this->resolve(['endpoint' => 'https://push.example.test/x', 'p256dh' => 'k', 'auth' => 'a', 'visitorId' => 'v1'])
        );
    }

    public function testRegistersForALoggedInCustomer(): void
    {
        $this->pushSubscriptionManager->expects(self::once())->method('register')
            ->with('https://push.example.test/x', 'k', 'a', 42, null);

        self::assertSame(
            ['ok' => true],
            $this->resolve(['endpoint' => 'https://push.example.test/x', 'p256dh' => 'k', 'auth' => 'a'], userId: 42)
        );
    }
}
