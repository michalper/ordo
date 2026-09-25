<?php
declare(strict_types=1);

namespace Ordo\Automation\Console\Command\ApiKey;

use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use Ordo\Automation\Model\AiAgent\ApiKeyGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento ordo:ai-agent:api-key:generate <label>` - issues a new AI-agent API key. No admin
 * grid for these (see ordo_ai_agent_api_key's own schema comment) - a store operator hands the
 * printed plaintext to whoever operates the agent/integration out of band, the same "shown once,
 * never retrievable again" model as any API token screen. The plaintext is never persisted -
 * only its sha256 hash (Model\AiAgent\ApiKeyGenerator::hash()) is, so losing this output means
 * generating a new key, not recovering the old one.
 */
class GenerateCommand extends Command
{
    public function __construct(
        private readonly ApiKeyGenerator $apiKeyGenerator,
        private readonly AiAgentApiKeyStore $apiKeyStore,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ordo:ai-agent:api-key:generate');
        $this->setDescription('Generate a new API key for an AI-agent commerce integration.');
        $this->addArgument('label', InputArgument::REQUIRED, 'Which agent/integration this key is for');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $label = (string) $input->getArgument('label');

        $generated = $this->apiKeyGenerator->generate();
        $this->apiKeyStore->create($label, $generated['hash']);
        $match = $this->apiKeyStore->findActiveByHash($generated['hash']);

        $output->writeln(sprintf('Generated API key #%d ("%s"):', $match['entity_id'] ?? 0, $label));
        $output->writeln($generated['plaintext']);
        $output->writeln('');
        $output->writeln(
            '<comment>This is the only time this key is shown - it is not stored anywhere and cannot be '
            . 'recovered. Send it to the integration operator now.</comment>'
        );

        return Command::SUCCESS;
    }
}
