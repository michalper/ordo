<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;

/**
 * Public POST endpoint - called by window.ordoCaptureChannelConsent(channel, consented)
 * (view/frontend/web/js/tracker.js), the theme's own integration point for capturing a
 * message-delivery channel consent choice wherever the theme has a UI moment for it (the
 * motivating case is an SMS/WhatsApp marketing-consent checkbox at checkout - see docs/adr/0083 -
 * but nothing here is checkout-specific). Logged-in customers only (ConsentManager is entirely
 * customer_id-keyed - a guest has no row to attach a preference to); a guest request is a
 * harmless no-op, same as every other no-op branch below.
 *
 * Delegates to ConsentPreferencesManagementInterface::setMyChannelConsent() - the exact same
 * service the storefront "Communication Preferences" page (Controller\Consent\SetChannel) and
 * REST/GraphQL already call, just adapted to a bare fetch() call instead of a full-page form POST
 * - no consent logic duplicated here. That service itself resolves the current customer (throws
 * NoSuchEntityException for a guest) and validates $channel against the real ConsentChannel enum
 * (throws LocalizedException otherwise) - both are reported back as the same {"ok": false}
 * no-op, not distinguished, since there is nothing actionable a theme's own JS could do with
 * either beyond "it didn't save."
 *
 * CSRF: same trust model as every other Controller\Track\* endpoint
 * (VisitorIdentityResolver::validateForCsrf()) - skipped for an anonymous request (which this
 * controller no-ops anyway), checked against Origin/Referer for a logged-in one, since this is a
 * bare fetch() call with no page-rendered form_key to attach.
 */
class CaptureChannelConsent extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly VisitorIdentityResolver $visitorIdentityResolver,
        private readonly ConsentPreferencesManagementInterface $consentPreferencesManagement,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isChannelConsentCaptureEnabled()) {
            return $result->setData(['ok' => false]);
        }

        $channel = (string) $this->getRequest()->getParam('channel');
        $consented = (bool) $this->getRequest()->getParam('consented');

        try {
            $this->consentPreferencesManagement->setMyChannelConsent($channel, $consented);
        } catch (LocalizedException) {
            return $result->setData(['ok' => false]);
        }

        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->visitorIdentityResolver->validateForCsrf($request);
    }
}
