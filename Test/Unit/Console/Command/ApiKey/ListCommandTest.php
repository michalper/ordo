<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Console\Command\ApiKey;

use Ordo\Automation\Console\Command\ApiKey\ListCommand;
use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ListCommandTest extends TestCase
{
    public function testExecutePrintsAMessageWhenNoKeysExist(): void
    {
        $store = $this->createStub(AiAgentApiKeyStore::class);
        $store->method('getAll')->willReturn([]);

        $tester = new CommandTester(new ListCommand($store));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No AI-agent API keys have been generated yet.', $tester->getDisplay());
    }

    public function testExecutePrintsATableOfEveryKeyWithoutTheKeyItself(): void
    {
        $store = $this->createStub(AiAgentApiKeyStore::class);
        $store->method('getAll')->willReturn([
            [
                'entity_id' => 1,
                'label' => 'Active Agent',
                'is_active' => true,
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => '2026-01-02 00:00:00',
            ],
            [
                'entity_id' => 2,
                'label' => 'Revoked Agent',
                'is_active' => false,
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => null,
            ],
        ]);

        $tester = new CommandTester(new ListCommand($store));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Active Agent', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('Revoked Agent', $display);
        self::assertStringContainsString('revoked', $display);
    }
}
