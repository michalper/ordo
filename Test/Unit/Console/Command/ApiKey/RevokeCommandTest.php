<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Console\Command\ApiKey;

use Ordo\Automation\Console\Command\ApiKey\RevokeCommand;
use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RevokeCommandTest extends TestCase
{
    public function testExecuteRevokesAndPrintsSuccessWhenAnActiveKeyMatched(): void
    {
        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->expects(self::once())->method('revoke')->with(5)->willReturn(true);

        $tester = new CommandTester(new RevokeCommand($store));
        $tester->execute(['id' => '5']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Revoked API key #5.', $tester->getDisplay());
    }

    public function testExecuteFailsWhenNoActiveKeyMatched(): void
    {
        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->expects(self::once())->method('revoke')->with(999)->willReturn(false);

        $tester = new CommandTester(new RevokeCommand($store));
        $tester->execute(['id' => '999']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No active API key found with id #999.', $tester->getDisplay());
    }
}
