<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Ai;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Talks to a self-hosted Ollama instance (https://ollama.com) — no data ever leaves the store,
 * consistent with this module's own "no external MA subscription" positioning (see ROADMAP.md's
 * "Local LLM (Ollama) content generation"). Uses Model\Http\JsonApiClient for the actual HTTP
 * mechanics, same as GoogleAdsSyncClient/MetaSyncClient/WhatsAppSender — only the URL/payload
 * shape here differs (Ollama's own `/api/generate` endpoint, not a third-party API).
 *
 * Deliberately fail-soft, same posture as every other send action's dependency on an external
 * service: a timeout, an unreachable host, or a malformed response returns null rather than
 * throwing, so Model\Campaign\Action\GenerateAiContent can fall back to its own static content
 * instead of blocking (or failing) the whole campaign dispatch over a local model being down.
 */
class OllamaClient
{
    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly Config $config,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string|null the generated text, or null on any failure (unreachable host, non-2xx,
     *   missing/empty "response" field) — never throws.
     */
    public function generate(string $prompt): ?string
    {
        $baseUrl = rtrim($this->config->getOllamaBaseUrl(), '/');
        if ($baseUrl === '') {
            return null;
        }

        $this->rateLimiter->throttle('ollama', (float) $this->config->getAiMaxRequestsPerSecond());

        try {
            $response = $this->jsonApiClient->postJson(
                $baseUrl . '/api/generate',
                [
                    'model' => $this->config->getOllamaModel(),
                    'prompt' => $prompt,
                    'stream' => false,
                ],
                [],
                'Ollama'
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Ordo_Automation: Ollama request failed: %s', $e->getMessage()));
            return null;
        }

        $text = $response['response'] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
