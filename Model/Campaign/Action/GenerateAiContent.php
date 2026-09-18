<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Ai\OllamaClient;

/**
 * Params: {"prompt": "Write a one-sentence...", "output_key": "ai_content_html", "fallback":
 * "static text"}. Closes ROADMAP.md's "Local LLM (Ollama) content generation" gap: personalizes
 * content per customer from context already in the dispatch (tags, RFM/CLV, order history, ...)
 * without any data leaving the store — same "no external MA subscription" positioning as the
 * rest of this module.
 *
 * Same always-write-something-into-context convention as AddDynamicContent/
 * AddProductRecommendations: output_key defaults to "ai_content_html" so a "send_email" action
 * later on the same campaign can render {{var ai_content_html|raw}} with no per-campaign wiring.
 *
 * Fail-soft on every possible way this can go wrong (feature disabled, no prompt configured, the
 * local Ollama instance unreachable or timing out) — falls back to the campaign author's own
 * static "fallback" text rather than ever blocking or failing the dispatch. A local LLM call is
 * strictly a nice-to-have layered on top of a working campaign, not a new point of failure for
 * one.
 */
class GenerateAiContent implements ActionInterface
{
    private const string DEFAULT_OUTPUT_KEY = 'ai_content_html';
    private const string PLACEHOLDER_PATTERN = '/\{\{([a-zA-Z0-9_]+)\}\}/';

    public function __construct(
        private readonly OllamaClient $ollamaClient,
        private readonly Config $config
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $outputKey = (string) ($params['output_key'] ?? self::DEFAULT_OUTPUT_KEY);
        $fallback = (string) ($params['fallback'] ?? '');
        $promptTemplate = trim((string) ($params['prompt'] ?? ''));

        if (!$this->config->isAiContentEnabled() || $promptTemplate === '') {
            $context[$outputKey] = $fallback;
            return;
        }

        $prompt = $this->fillPlaceholders($promptTemplate, $context);
        $generated = $this->ollamaClient->generate($prompt);

        $context[$outputKey] = $generated ?? $fallback;
    }

    /**
     * Replaces {{context_key}} in the prompt template with the matching scalar value already in
     * the campaign dispatch context (e.g. {{customer_id}}, {{order_total}}, a segment/RFM tag
     * set earlier in the same chain) - an unknown or non-scalar key is left as literal text
     * rather than silently dropped, so a typo'd placeholder is visible in the generated output
     * instead of disappearing without a trace.
     *
     * @param array<string, mixed> $context
     */
    private function fillPlaceholders(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($context): string {
                $value = $context[$matches[1]] ?? null;
                return is_scalar($value) ? (string) $value : $matches[0];
            },
            $template
        );
    }
}
