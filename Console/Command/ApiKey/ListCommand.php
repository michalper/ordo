<?php
declare(strict_types=1);

namespace Ordo\Automation\Console\Command\ApiKey;

use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento ordo:ai-agent:api-key:list` - never prints the key itself (only its hash is
 * stored, and that's not shown either); this is purely for an admin to tell a live integration
 * from an abandoned one (last_used_at) before deciding whether to revoke it.
 */
class ListCommand extends Command
{
    public function __construct(
        private readonly AiAgentApiKeyStore $apiKeyStore,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ordo:ai-agent:api-key:list');
        $this->setDescription('List every AI-agent API key (active and revoked).');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->apiKeyStore->getAll();

        if ($keys === []) {
            $output->writeln('No AI-agent API keys have been generated yet.');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Label', 'Status', 'Created At', 'Last Used At']);
        foreach ($keys as $key) {
            $table->addRow([
                $key['entity_id'],
                $key['label'],
                $key['is_active'] ? 'active' : 'revoked',
                $key['created_at'],
                $key['last_used_at'] ?? '-',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
