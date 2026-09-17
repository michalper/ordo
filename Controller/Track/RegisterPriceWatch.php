<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;

/**
 * Public, unauthenticated endpoint tracker.js posts to when a visitor clicks "Notify me" on a
 * PDP for a price drop or back-in-stock alert. Identity resolution and CSRF trust model are
 * copied from Controller\Track\RegisterPushSubscription verbatim — see that class's own
 * docblock for the full reasoning: CSRF is only skipped for anonymous registrations, a
 * logged-in registration must be checked against Origin/Referer since this is a bare fetch()
 * call with no page-rendered form_key.
 */
class RegisterPriceWatch extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const string VISITOR_ID_COOKIE = 'ordo_visitor_id';

    private const array VALID_WATCH_TYPES = [
        PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
        PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK,
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PriceWatchSubscriptionManager $priceWatchSubscriptionManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerSession $customerSession,
        private readonly CookieManagerInterface $cookieManager,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isPriceWatchEnabled()) {
            return $result->setData(['ok' => false, 'reason' => 'price_watch_disabled']);
        }

        $productId = (int) $this->getRequest()->getParam('product_id');
        $watchType = $this->getRequest()->getParam('watch_type');
        $watchType = is_string($watchType) ? trim($watchType) : '';
        $visitorId = (string) ($this->cookieManager->getCookie(self::VISITOR_ID_COOKIE) ?? '');

        $hasNoIdentity = $visitorId === '' && !$this->customerSession->isLoggedIn();
        if ($productId <= 0 || !in_array($watchType, self::VALID_WATCH_TYPES, true) || $hasNoIdentity) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_payload']);
        }

        try {
            $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return $result->setData(['ok' => false, 'reason' => 'invalid_product']);
        }

        $customerId = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null;

        $this->priceWatchSubscriptionManager->register($productId, $watchType, $customerId, $visitorId ?: null);

        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return true;
        }

        $origin = $request->getHeader('Origin');
        // No Magento core alternative parses a URL into its component parts.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $originHost = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : false;
        if (is_string($originHost)) {
            return $originHost === $request->getHttpHost();
        }

        // Some browsers omit Origin on a same-origin fetch() in certain configurations - fall
        // back to Referer rather than failing every logged-in browser outright.
        $referer = $request->getHeader('Referer');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $refererHost = is_string($referer) ? parse_url($referer, PHP_URL_HOST) : false;

        return is_string($refererHost) && $refererHost === $request->getHttpHost();
    }
}
