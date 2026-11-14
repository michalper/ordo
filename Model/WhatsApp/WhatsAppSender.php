<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\WhatsApp;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;

/**
 * Sends a WhatsApp message via the real Meta Graph API - real HTTP, no SDK, same choice as
 * WhatsAppTemplateClient above. send() only ever sends an approved template, never a free-form
 * message: outside the 24h customer-service window a free-form send is rejected by Meta anyway,
 * and a campaign dispatch has no reliable way to know whether that window is currently open for
 * a given recipient, so Model\Campaign\Action\SendWhatsApp never even offers the choice.
 * sendFreeform() below is the one exception - see its own docblock for why it is safe.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-message-templates
 */
class WhatsAppSender
{
    private const string API_VERSION = 'v26.0';

    public function __construct(
        private readonly JsonApiClient $jsonApiClient,
        private readonly Config $config,
        private readonly OutboundRateLimiter $rateLimiter
    ) {
    }

    /**
     * @param string[] $params Positional values for the template's {{1}}, {{2}}, ... body
     *   placeholders, in order - empty when the template has none.
     * @return string the provider message id (messages[0].id) - what a later delivery-status
     *   webhook correlates back to this send.
     */
    public function send(string $toPhone, string $metaTemplateName, string $language, array $params): string
    {
        $this->rateLimiter->throttle('whatsapp', (float) $this->config->getWhatsAppMaxRequestsPerSecond());

        $components = [];
        if ($params !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => $value],
                    $params
                ),
            ];
        }

        $body = $this->request(
            sprintf(
                'https://graph.facebook.com/%s/%s/messages',
                self::API_VERSION,
                $this->config->getWhatsAppPhoneNumberId()
            ),
            [
                'messaging_product' => 'whatsapp',
                'to' => $toPhone,
                'type' => 'template',
                'template' => [
                    'name' => $metaTemplateName,
                    'language' => ['code' => $language],
                    'components' => $components,
                ],
            ]
        );

        return $this->extractMessageId($body);
    }

    /**
     * Sends a single free-form (non-template) text message - safe ONLY because every caller of
     * this method is a direct, admin-initiated reply to a message the customer just sent
     * (Model\Conversation\OutboundReplySender, via Controller\Adminhtml\ConversationMessage\
     * Reply), which Meta's own 24h customer-service-window rule always permits a free-form reply
     * within. NEVER call this from a campaign action (Model\Campaign\Action\SendWhatsApp) - a
     * campaign dispatch has no reliable way to know whether that window is open for a given
     * recipient, see that class's own docblock for why it only ever sends a template.
     *
     * @return string the provider message id, same shape as send() above.
     */
    public function sendFreeform(string $toPhone, string $message): string
    {
        $this->rateLimiter->throttle('whatsapp', (float) $this->config->getWhatsAppMaxRequestsPerSecond());

        $body = $this->request(
            sprintf(
                'https://graph.facebook.com/%s/%s/messages',
                self::API_VERSION,
                $this->config->getWhatsAppPhoneNumberId()
            ),
            [
                'messaging_product' => 'whatsapp',
                'to' => $toPhone,
                'type' => 'text',
                'text' => ['body' => $message],
            ]
        );

        return $this->extractMessageId($body);
    }

    /**
     * @param array<mixed> $body
     */
    private function extractMessageId(array $body): string
    {
        $messageId = $body['messages'][0]['id'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new \RuntimeException('Meta messages:send response had no message id.');
        }

        return $messageId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function request(string $url, array $payload): array
    {
        return $this->jsonApiClient->postJson($url, $payload, [
            'Authorization' => 'Bearer ' . $this->config->getWhatsAppAccessToken(),
        ], 'Meta Graph API');
    }
}
