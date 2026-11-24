<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use Ordo\Automation\Model\Recommendation\ProductRecommender;

/**
 * Public, unauthenticated endpoint - called by window.ordoRenderCrossSell(orderId,
 * containerSelector) (view/frontend/web/js/tracker.js), the theme's own order-thank-you-page
 * integration point: Ordo doesn't inject anything into checkout_onepage_success.xml itself, same
 * "theme calls a documented function with data it already has" shape as
 * window.ordoRenderSocialProof()/window.ordoRenderLowStock() on the PDP.
 *
 * $orderId is the order's own entity id (what Magento\Checkout\Block\Onepage\Success::
 * getOrderId() returns - the natural value a theme's own thank-you template already has in
 * scope), but it is NEVER trusted on its own: this is a public GET endpoint, so a bare integer
 * order id would otherwise let anyone enumerate ids and read back a stranger's own purchase-
 * affinity signal (an IDOR-shaped leak, low-sensitivity but real). It's only ever honored when it
 * matches CheckoutSession::getLastRealOrder()->getIncrementId() - the same order-ownership check
 * Magento's own core Success block relies on internally - i.e. only the order THIS exact browser
 * session just placed. getLastRealOrder() (a real, declared method), not getLastRealOrderId()
 * (a magic accessor via Magento\Framework\Session\SessionManager::__call(), which PHPUnit can't
 * stub directly - MockBuilder::addMethods(), the mechanism for exactly this case, was removed in
 * PHPUnit 10+) - same information, trivially mockable.
 *
 * Reuses Model\Recommendation\ProductRecommender/ProductRecommendationRenderer wholesale - the
 * same co-purchase-affinity ranking and email-grade HTML grid markup
 * Model\Campaign\Action\AddProductRecommendations and Model\ContentBlock\Producer\
 * ProductFeedProducer already use, so this widget needs zero new recommendation logic of its
 * own. See docs/adr/0082.
 */
class CrossSell extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRecommender $productRecommender,
        private readonly ProductRecommendationRenderer $productRecommendationRenderer,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isCrossSellWidgetEnabled()) {
            return $result->setData(['html' => '']);
        }

        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return $result->setData(['html' => '']);
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return $result->setData(['html' => '']);
        }

        $lastRealIncrementId = (string) $this->checkoutSession->getLastRealOrder()->getIncrementId();
        if ((string) $order->getIncrementId() !== $lastRealIncrementId) {
            return $result->setData(['html' => '']);
        }

        $customerId = (int) $order->getCustomerId();
        $skus = $this->productRecommender->getRecommendedSkus($customerId, $this->config->getCrossSellItemCount());
        $html = $this->productRecommendationRenderer->renderHtml($skus, (string) __('You might also like'));

        return $result->setData(['html' => $html]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
