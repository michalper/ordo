<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\WellKnown;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;

/**
 * Serves "/.well-known/ai-plugin.json" (matched by App\Router\WellKnownRouter, not a normal
 * frontName route) — a discovery document pointing an autonomous AI shopping agent at this
 * store's ai_agent product feed (Model\ProductFeed\AiAgentFeedGenerator). Public, unauthenticated
 * GET, same trust model as the feed controllers themselves (Controller\ProductFeed\
 * AbstractFeedAction) — this only ever describes a publicly-served feed, never anything
 * requiring auth. Gated behind the same Config::isAiAgentEnabled() toggle the feed itself checks,
 * since advertising a feed nobody published is worse than not advertising one at all.
 */
class AiPluginManifest extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        if (!$this->config->isAiAgentEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            return $result;
        }

        $name = $this->config->getAiAgentName($storeId);
        $description = $this->config->getAiAgentDescription($storeId);

        $result->setData([
            'schema_version' => 'v1',
            'name_for_human' => $name,
            'name_for_model' => $name,
            'description_for_human' => $description,
            'description_for_model' => $description,
            'auth' => ['type' => 'none'],
            'api' => [
                'type' => 'product_feed',
                'format' => 'application/json',
                'url' => $store->getBaseUrl() . 'ordo/productfeed/aiagent',
            ],
            'contact_email' => $this->config->getAiAgentContactEmail($storeId),
        ]);

        return $result;
    }
}
