<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Ai;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Model\Ai\OllamaClient;

/**
 * "Test Ollama Connection" button on the AI Content config page (see
 * Block\Adminhtml\System\Config\TestOllamaConnection) - lets an admin verify the configured
 * self-hosted Ollama instance actually responds before relying on it in a real campaign, rather
 * than only discovering a misconfiguration (wrong URL, model not pulled, instance down) the
 * first time a "Generate AI Content" action's own fail-soft fallback silently kicks in during a
 * real dispatch.
 *
 * Calls OllamaClient::generate() directly with a fixed, trivial prompt - the exact same code
 * path a real campaign action uses, so a green result here is a genuine guarantee, not a
 * separate connectivity check that could pass while the real path still fails.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::config';

    private const string TEST_PROMPT = 'Reply with the single word: OK';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly OllamaClient $ollamaClient
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $response = $this->ollamaClient->generate(self::TEST_PROMPT);

        if ($response === null) {
            return $result->setData([
                'ok' => false,
                'message' => (string) __(
                    'No response - check the Base URL/Model below, and that the Ollama instance '
                    . 'is reachable from this server.'
                ),
            ]);
        }

        return $result->setData([
            'ok' => true,
            'message' => (string) __('Connected. Response: "%1"', $response),
        ]);
    }
}
