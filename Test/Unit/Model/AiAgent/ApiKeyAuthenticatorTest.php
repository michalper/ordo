<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use Ordo\Automation\Model\AiAgent\ApiKeyAuthenticator;
use Ordo\Automation\Model\AiAgent\ApiKeyGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ApiKeyAuthenticatorTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAuthenticateReturnsNullForAnEmptyKey(): void
    {
        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->expects(self::never())->method('findActiveByHash');

        $authenticator = new ApiKeyAuthenticator($store, new ApiKeyGenerator());

        self::assertNull($authenticator->authenticate(''));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAuthenticateReturnsNullWhenNoActiveKeyMatchesTheHash(): void
    {
        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->method('findActiveByHash')->willReturn(null);
        $store->expects(self::never())->method('touchLastUsed');

        $authenticator = new ApiKeyAuthenticator($store, new ApiKeyGenerator());

        self::assertNull($authenticator->authenticate('oaa_wrongkey'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAuthenticateTouchesLastUsedAndReturnsTheHashWhenAnActiveKeyMatches(): void
    {
        $generator = new ApiKeyGenerator();
        $hash = $generator->hash('oaa_realkey');

        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->expects(self::once())->method('findActiveByHash')->with($hash)
            ->willReturn(['entity_id' => 7, 'label' => 'My Agent']);
        $store->expects(self::once())->method('touchLastUsed')->with(7);

        $authenticator = new ApiKeyAuthenticator($store, $generator);

        self::assertSame($hash, $authenticator->authenticate('oaa_realkey'));
    }
}
