<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\UrlInterface;

abstract class GenericButton
{
    public function __construct(
        protected readonly Context $context
    ) {
    }

    protected function getUrlBuilder(): UrlInterface
    {
        return $this->context->getUrlBuilder();
    }

    protected function getEntityId(): ?int
    {
        $entityId = $this->context->getRequest()->getParam('entity_id');
        return $entityId ? (int) $entityId : null;
    }

    protected function getUrl(string $route = '*/*/', array $params = []): string
    {
        return $this->getUrlBuilder()->getUrl($route, $params);
    }
}
