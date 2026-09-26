<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\AiAgent;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Ordo\Automation\Api\AiAgentQuoteManagementInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AiAgent\ApiKeyAuthenticator;
use Ordo\Automation\Model\AiAgent\InboundRateLimiter;

/**
 * `POST /V1/ordo/ai-agent/quote` is `<resource ref="anonymous"/>` (see etc/webapi.xml) - Magento's
 * ACL layer does nothing for it, so this `before` plugin is the actual gate: it reads the plain
 * "Authorization: Bearer <key>" header (Model\AiAgent\ApiKeyAuthenticator has no other way to see
 * it - webapi services don't get the HTTP request injected the way a controller does), and
 * verifies + throttles before AiAgentQuoteManagement ever builds a quote. Scoped to the
 * `webapi_rest` area only (etc/webapi_rest/di.xml), same reasoning as Plugin\Webapi\
 * SparseFieldsetPlugin - headers are a REST concept, SOAP/GraphQL requests have no Authorization
 * header for RestRequest to read.
 */
class AuthenticateQuoteRequestPlugin
{
    public function __construct(
        private readonly RestRequest $request,
        private readonly ApiKeyAuthenticator $apiKeyAuthenticator,
        private readonly InboundRateLimiter $inboundRateLimiter,
        private readonly Config $config
    ) {
    }

    /**
     * @param AiAgentQuoteManagementInterface $subject
     */
    public function beforeGetQuote(AiAgentQuoteManagementInterface $subject): void
    {
        if (!$this->config->isAiAgentEnabled()) {
            throw new WebapiException(
                __('AI-agent commerce endpoints are disabled.'),
                0,
                WebapiException::HTTP_NOT_FOUND
            );
        }

        $plaintextKey = $this->extractBearerToken((string) $this->request->getHeader('Authorization'));
        $keyHash = $plaintextKey !== null ? $this->apiKeyAuthenticator->authenticate($plaintextKey) : null;

        if ($keyHash === null) {
            throw new WebapiException(__('Missing or invalid API key.'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        if (!$this->inboundRateLimiter->isAllowed($keyHash)) {
            throw new WebapiException(__('Rate limit exceeded.'), 0, WebapiException::HTTP_TOO_MANY_REQUESTS);
        }
    }

    private function extractBearerToken(string $authorizationHeader): ?string
    {
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authorizationHeader, strlen('Bearer '));

        return $token !== '' ? $token : null;
    }
}
