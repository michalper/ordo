<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Email;

use Magento\Store\Api\Data\StoreInterface;
use Ordo\Automation\Model\ConsentChannel;

/**
 * Shared "what a marketing email's unsubscribe footer link and List-Unsubscribe header both
 * need" builder - Model\Campaign\Action\SendEmail is the only caller (see
 * docs/adr/0055-email-one-click-unsubscribe.md's own scope note: deliberately just the campaign
 * engine's own send_email action, not every TransportBuilder-based email this module sends), but
 * kept as its own class rather than inlined there, the same reasoning Model\SalesRepEmailContext
 * already established for a signature block shared across every automated email - a second
 * caller needing the same unsubscribe link (another campaign action, a future transactional
 * sender) reuses this instead of re-deriving the URL shape.
 */
class UnsubscribeContext
{
    private const string ROUTE_PATH = '/ordo/email/unsubscribe/token/';

    public function __construct(
        private readonly UnsubscribeTokenManager $tokenManager
    ) {
    }

    /**
     * @return array{unsubscribe_url: string}
     */
    public function getForCustomer(int $customerId, StoreInterface $store): array
    {
        $token = $this->tokenManager->generate($customerId, ConsentChannel::Email);
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        return ['unsubscribe_url' => $baseUrl . self::ROUTE_PATH . $token];
    }
}
