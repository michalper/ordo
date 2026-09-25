<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

/**
 * Verifies a plaintext API key presented by an AI-agent request against ordo_ai_agent_api_key
 * (AiAgentApiKeyStore) - the only inbound auth mechanism this module has, since every other
 * public endpoint (product feeds, order-approval decision links) is either fully anonymous or
 * trusts an opaque per-record token, neither of which fits a machine client making repeated calls
 * across many requests. Never compares the plaintext directly - only its sha256 hash is ever
 * looked up, matching what's persisted (ApiKeyGenerator).
 */
class ApiKeyAuthenticator
{
    public function __construct(
        private readonly AiAgentApiKeyStore $apiKeyStore,
        private readonly ApiKeyGenerator $apiKeyGenerator
    ) {
    }

    /**
     * @return string|null the matched key's hash (the identifier InboundRateLimiter throttles by),
     *   or null if $plaintextKey doesn't match any active key.
     */
    public function authenticate(string $plaintextKey): ?string
    {
        if ($plaintextKey === '') {
            return null;
        }

        $hash = $this->apiKeyGenerator->hash($plaintextKey);
        $match = $this->apiKeyStore->findActiveByHash($hash);

        if ($match === null) {
            return null;
        }

        $this->apiKeyStore->touchLastUsed($match['entity_id']);

        return $hash;
    }
}
