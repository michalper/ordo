<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\UrlInterface;

/**
 * Was three byte-identical copies (Campaign/FreeGiftOffer/Segment Edit\GenericButton) — every
 * admin edit form's toolbar button class only needs the entity_id request param and a URL
 * builder, none of which differs per entity, so one shared class replaces all three.
 */
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

    /**
     * @param array<string, mixed> $params
     */
    protected function getUrl(string $route = '*/*/', array $params = []): string
    {
        return $this->getUrlBuilder()->getUrl($route, $params);
    }
}
