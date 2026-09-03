<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The one place the delivery-status callback URL gets built — used both when TwilioSmsSender
 * tells Twilio where to POST status updates, and when Controller\Sms\StatusCallback re-derives
 * the same URL to verify Twilio's X-Twilio-Signature. Both sides MUST compute the identical URL
 * or the signature check is meaningless, hence one shared builder instead of two copies that
 * could drift.
 */
class CallbackUrlBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Forces HTTPS — a deliberate hardening beyond this module's only other precedent for
     * building a public callback URL (Model\OrderApprovalManagement::getDecisionLinksById(),
     * which doesn't force it). Twilio requires a real HTTPS endpoint, and a plain-HTTP callback
     * would leak delivery status over an unencrypted channel even if Twilio accepted it.
     */
    public function getSmsStatusCallbackUrl(): string
    {
        $baseUrl = rtrim((string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');

        return $baseUrl . '/ordo/sms/statuscallback';
    }
}
