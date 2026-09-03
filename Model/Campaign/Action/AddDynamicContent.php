<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\ContentBlock\ProducerPool;
use Ordo\Automation\Model\ContentBlockRepository;

/**
 * Params: {"content_block_id": 3, "output_key": "dynamic_content_html"} — output_key defaults
 * to "dynamic_content_html" so a "send_email" action after this one on the same campaign can
 * render {{var dynamic_content_html|raw}} without any per-campaign configuration, same
 * always-write-something-into-context convention as AddProductRecommendations. A missing/
 * disabled/unresolvable content block is a fail-quiet no-op (empty string written, not an
 * exception) — the email template's own {{depend}} block handles the empty case.
 */
class AddDynamicContent implements ActionInterface
{
    private const DEFAULT_OUTPUT_KEY = 'dynamic_content_html';

    public function __construct(
        private readonly ContentBlockRepository $contentBlockRepository,
        private readonly ProducerPool $producerPool
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $outputKey = (string) ($params['output_key'] ?? self::DEFAULT_OUTPUT_KEY);
        $blockId = (int) ($params['content_block_id'] ?? 0);
        if ($blockId <= 0) {
            $context[$outputKey] = '';
            return;
        }

        $block = $this->contentBlockRepository->getById($blockId);
        if ($block === null || !$block->isEnabled()) {
            $context[$outputKey] = '';
            return;
        }

        $producer = $this->producerPool->get($block->getType());
        $context[$outputKey] = $producer !== null ? $producer->render($block) : '';
    }
}
