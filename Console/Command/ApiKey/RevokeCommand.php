<?php
declare(strict_types=1);

namespace Ordo\Automation\Console\Command\ApiKey;

use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento ordo:ai-agent:api-key:revoke <id>` - the id is the one GenerateCommand or
 * ListCommand printed, never the key itself (that's never persisted in a recoverable form to
 * begin with). Sets is_active=0 rather than deleting the row, so a revoked key's last_used_at
 * history isn't lost.
 */
class RevokeCommand extends Command
{
    public function __construct(
        private readonly AiAgentApiKeyStore $apiKeyStore,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ordo:ai-agent:api-key:revoke');
        $this->setDescription('Revoke an AI-agent API key by its id.');
        $this->addArgument('id', InputArgument::REQUIRED, 'The key id, as printed by :generate or :list');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityId = (int) $input->getArgument('id');

        if (!$this->apiKeyStore->revoke($entityId)) {
            $output->writeln(sprintf('<error>No active API key found with id #%d.</error>', $entityId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Revoked API key #%d.', $entityId));

        return Command::SUCCESS;
    }
}
