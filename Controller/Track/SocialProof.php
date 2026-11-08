<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\ProductViewCounter;

/**
 * Public, unauthenticated endpoint - called by window.ordoRenderSocialProof(sku, containerSelector)
 * (view/frontend/web/js/tracker.js), the theme's own PDP integration point for "N people viewed
 * this today", same shape as window.ordoTrack()/window.ordoSubscribeToPriceWatch(): Ordo doesn't
 * inject anything into catalog_product_view.xml itself, the theme calls this with the product it
 * already knows and a container it has placed in its own markup.
 *
 * The message is formatted here, server-side, rather than the raw count being sent for tracker.js
 * to template client-side - Helper\Config::getSocialProofMessageTemplate() is ordinary per-store
 * admin-authored config text, not a hardcoded string in a static JS file, and whether the count
 * even clears the configured minimum is a decision that belongs with that same config, not
 * duplicated into the frontend.
 */
class SocialProof extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    private const int MAX_SKU_LENGTH = 64;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ProductViewCounter $productViewCounter,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isSocialProofEnabled()) {
            return $result->setData(['message' => null]);
        }

        $sku = $this->getRequest()->getParam('sku');
        $sku = is_scalar($sku) ? substr((string) $sku, 0, self::MAX_SKU_LENGTH) : '';
        if ($sku === '') {
            return $result->setData(['message' => null]);
        }

        $viewCount = $this->productViewCounter->countViewsToday($sku);
        if ($viewCount < $this->config->getSocialProofMinimumViews()) {
            return $result->setData(['message' => null]);
        }

        $message = str_replace('{count}', (string) $viewCount, $this->config->getSocialProofMessageTemplate());

        return $result->setData(['message' => $message]);
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
