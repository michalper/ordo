<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Console\Command\ApiKey;

use Ordo\Automation\Console\Command\ApiKey\GenerateCommand;
use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use Ordo\Automation\Model\AiAgent\ApiKeyGenerator;
use Ordo\Automation\Model\RandomTokenGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateCommandTest extends TestCase
{
    public function testExecutePrintsTheGeneratedPlaintextKeyAndItsId(): void
    {
        $generator = new ApiKeyGenerator(new RandomTokenGenerator());

        $store = $this->createMock(AiAgentApiKeyStore::class);
        $store->expects(self::once())->method('create')->with('My Agent', self::isString());
        $store->method('findActiveByHash')->willReturn(['entity_id' => 5, 'label' => 'My Agent']);

        $command = new GenerateCommand($generator, $store);
        $tester = new CommandTester($command);
        $tester->execute(['label' => 'My Agent']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Generated API key #5 ("My Agent")', $tester->getDisplay());
        self::assertStringContainsString('This is the only time this key is shown', $tester->getDisplay());
    }
}
