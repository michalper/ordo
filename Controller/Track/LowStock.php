<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Track;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Track\ProductStockChecker;

/**
 * Public, unauthenticated endpoint - called by window.ordoRenderLowStock(sku, containerSelector)
 * (view/frontend/web/js/tracker.js), same theme-integration shape as
 * window.ordoRenderSocialProof(sku, containerSelector): Ordo doesn't inject anything into
 * catalog_product_view.xml itself, the theme calls this with the product it already knows and a
 * container it has placed in its own markup.
 *
 * The message is formatted here, server-side, same reasoning Controller\Track\SocialProof's own
 * docblock already gives: Helper\Config::getLowStockMessageTemplate() is ordinary per-store
 * admin-authored config text, and whether the qty even clears the configured threshold is a
 * decision that belongs with that same config, not duplicated into the frontend.
 */
class LowStock extends Action implements HttpGetActionInterface, CsrfAwareActionInterface
{
    private const int MAX_SKU_LENGTH = 64;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductStockChecker $productStockChecker,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->config->isLowStockWidgetEnabled()) {
            return $result->setData(['message' => null]);
        }

        $sku = $this->getRequest()->getParam('sku');
        $sku = is_scalar($sku) ? substr((string) $sku, 0, self::MAX_SKU_LENGTH) : '';
        if ($sku === '') {
            return $result->setData(['message' => null]);
        }

        try {
            $productId = (int) $this->productRepository->get($sku)->getId();
        } catch (NoSuchEntityException) {
            return $result->setData(['message' => null]);
        }

        $qty = $this->productStockChecker->getQty($productId);
        if ($qty === null || $qty <= 0 || $qty > $this->config->getLowStockThreshold()) {
            return $result->setData(['message' => null]);
        }

        $message = str_replace('{qty}', (string) (int) $qty, $this->config->getLowStockMessageTemplate());

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
