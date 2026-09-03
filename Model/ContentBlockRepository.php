<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;

/**
 * Simple direct resource-model persistence, same pattern the campaign engine's own controllers
 * use for admin CRUD (no WebAPI surface for content blocks, so no Api\...RepositoryInterface/
 * SearchResults ceremony is needed here — see getById()'s null-safe contract, which
 * Model\Campaign\Action\AddDynamicContent relies on to fail quiet rather than throw).
 */
class ContentBlockRepository
{
    public function __construct(
        private readonly ContentBlockFactory $contentBlockFactory,
        private readonly ContentBlockResource $contentBlockResource
    ) {
    }

    /**
     * Null-safe: returns null for a missing/unset id instead of throwing, so callers on a hot
     * dispatch path (AddDynamicContent) can fail quiet without a try/catch.
     */
    public function getById(int $id): ?ContentBlock
    {
        if ($id <= 0) {
            return null;
        }

        $block = $this->contentBlockFactory->create();
        $this->contentBlockResource->load($block, $id);

        return $block->getId() ? $block : null;
    }

    public function save(ContentBlock $block): ContentBlock
    {
        $this->contentBlockResource->save($block);
        return $block;
    }

    public function delete(ContentBlock $block): void
    {
        $this->contentBlockResource->delete($block);
    }
}
