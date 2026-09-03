<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock\Renderer;

use Magento\Framework\Escaper;

/**
 * Turns a parsed RSS feed's items into an inline-styled HTML fragment suitable for embedding
 * straight into a campaign email body, matching the visual style of
 * Model\Recommendation\ProductRecommendationRenderer's product grid — email HTML must be
 * table-based with inline styles (no external CSS, no flexbox/grid), see
 * view/frontend/email/campaign_generic.html.
 */
class RssItemRenderer
{
    private const DEFAULT_ITEM_COUNT = 5;

    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @param array<int, array{title?: string, link?: string, description?: string}> $items
     */
    public function render(array $items, int $itemCount = self::DEFAULT_ITEM_COUNT): string
    {
        if ($items === []) {
            return '';
        }

        if ($itemCount <= 0) {
            $itemCount = self::DEFAULT_ITEM_COUNT;
        }

        // $items is already known non-empty and $itemCount already known positive above, so
        // array_slice() here is always non-empty and $rows always ends up non-empty too —
        // no "still nothing to render" case survives to check for below.
        $rows = [];
        foreach (array_slice($items, 0, $itemCount) as $item) {
            $rows[] = $this->renderItemRow($item);
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr class="rss-content-block"><td>'
            . '<p style="font-weight:bold;margin:0 0 10px;">Latest updates</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . implode('', $rows)
            . '</table>'
            . '</td></tr></table>';
    }

    /**
     * @param array{title?: string, link?: string, description?: string} $item
     */
    private function renderItemRow(array $item): string
    {
        $title = $this->escaper->escapeHtml((string) ($item['title'] ?? ''));
        $link = $this->safeLink((string) ($item['link'] ?? ''));
        $description = $this->escaper->escapeHtml((string) ($item['description'] ?? ''));

        $titleHtml = $link !== ''
            ? '<a href="' . $link . '" style="text-decoration:none;color:#333333;font-weight:bold;">' . $title . '</a>'
            : '<span style="font-weight:bold;">' . $title . '</span>';

        return '<tr><td style="padding:10px 0;border-bottom:1px solid #eeeeee;">'
            . '<p style="margin:0 0 5px;">' . $titleHtml . '</p>'
            . '<p style="margin:0;color:#666666;">' . $description . '</p>'
            . '</td></tr>';
    }

    /**
     * escapeHtml() alone doesn't neutralize a javascript:/data: URI scheme — a compromised feed
     * can supply <link>javascript:...</link> and have it land unmodified in an href attribute.
     * Only http/https links from the feed are ever rendered as a link; anything else is dropped
     * (title still renders, just as plain text instead of a link).
     */
    private function safeLink(string $link): string
    {
        $link = trim($link);
        if ($link === '' || !preg_match('#^https?://#i', $link)) {
            return '';
        }

        return $this->escaper->escapeHtml($link);
    }
}
