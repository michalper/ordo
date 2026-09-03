<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock\Producer;

use Ordo\Automation\Model\ContentBlock;

/**
 * Config: {"html": "<p>...</p>"} — raw, admin-authored HTML, deliberately not re-escaped here.
 * The admin who typed it into the content block form is the same trust boundary as anyone who
 * edits an email template directly; this is not user-submitted content.
 */
class SnippetProducer implements ProducerInterface
{
    public function render(ContentBlock $block): string
    {
        $config = $block->getConfigArray();
        return (string) ($config['html'] ?? '');
    }
}
