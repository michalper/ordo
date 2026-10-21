<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\WhatsApp;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;

/**
 * Submits a WhatsApp message template to Meta for approval, and polls its current status - the
 * two Meta Graph API calls Controller\Adminhtml\WhatsAppTemplate\SubmitForReview and
 * RefreshStatus each make. Real HTTP calls to the documented Graph API REST endpoints, no SDK -
 * same "plain HTTP over a new SDK" choice this module already made for Google Ads/Meta ad-
 * audience sync (see Model/AdAudience/GoogleAdsSyncClient.php).
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/guides/create-templates
 */
class WhatsAppTemplateClient
{
    private const string API_VERSION = 'v20.0';

    private const string SERVICE_NAME = 'Meta Graph API';

    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly Config $config
    ) {
    }

    /**
     * @return string Meta's own template id for this submission.
     */
    public function submitTemplate(
        string $metaTemplateName,
        string $category,
        string $language,
        string $bodyText
    ): string {
        $body = $this->request(
            'POST',
            sprintf(
                'https://graph.facebook.com/%s/%s/message_templates',
                self::API_VERSION,
                $this->config->getWhatsAppBusinessAccountId()
            ),
            [
                'name' => $metaTemplateName,
                'category' => strtoupper($category),
                'language' => $language,
                'components' => [
                    ['type' => 'BODY', 'text' => $bodyText],
                ],
            ]
        );

        $id = $body['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Meta message_templates:create response had no template id.');
        }

        return $id;
    }

    /**
     * @return array{status: string, rejectionReason: ?string} status is Meta's own uppercase
     *   value (PENDING/APPROVED/REJECTED/...) - callers map it to WhatsAppTemplate::STATUS_*.
     */
    public function getTemplateStatus(string $metaTemplateId): array
    {
        $body = $this->request(
            'GET',
            sprintf(
                'https://graph.facebook.com/%s/%s?fields=status,rejected_reason',
                self::API_VERSION,
                $metaTemplateId
            ),
            null
        );

        $status = $body['status'] ?? null;
        if (!is_string($status) || $status === '') {
            throw new \RuntimeException('Meta template status response had no status.');
        }

        $rejectionReason = $body['rejected_reason'] ?? null;

        return [
            'status' => $status,
            'rejectionReason' => is_string($rejectionReason) && $rejectionReason !== '' ? $rejectionReason : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<mixed>
     */
    private function request(string $method, string $url, ?array $payload): array
    {
        $authHeader = ['Authorization' => 'Bearer ' . $this->config->getWhatsAppAccessToken()];

        return $method === 'GET'
            ? $this->jsonApiClient->getJson($url, $authHeader, self::SERVICE_NAME)
            : $this->jsonApiClient->postJson($url, $payload ?? [], $authHeader, self::SERVICE_NAME);
    }
}
