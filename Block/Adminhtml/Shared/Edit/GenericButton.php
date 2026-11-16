<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Phrase;
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

    /**
     * The "confirm, then submit a real POST" `on_click` shared by every toolbar button in this
     * module whose target controller implements HttpPostActionInterface only (Delete, Publish,
     * RefreshStatus, SubmitForReview) — deleteConfirm's 3-argument form (mage/dataPost.js) builds
     * and submits that POST with form_key auto-attached, unlike its 2-argument form (mage/
     * adminhtml/globals.js's setLocation()), which navigates via a plain GET and 404s before ever
     * reaching the controller (app/etc/di.xml's HTTP method map). Found independently, by
     * actually clicking the button in a real browser, on four separate buttons before this was
     * pulled into one place.
     */
    protected function confirmedPostOnClick(Phrase $confirmMessage, string $route): string
    {
        return sprintf(
            "deleteConfirm('%s', '%s', {data: {}})",
            $confirmMessage,
            $this->getUrl($route, ['entity_id' => $this->getEntityId()])
        );
    }
}
