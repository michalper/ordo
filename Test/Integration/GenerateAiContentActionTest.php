<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MutableScopeConfig;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Ordo\Automation\Model\Campaign\Action\GenerateAiContent;
use PHPUnit\Framework\TestCase;

/**
 * Closes SCENARIOS.md #1c's last open row: GenerateAiContentTest (Test/Unit) already covers the
 * fail-soft *decision* (disabled/no prompt/Ollama returns null -> fallback) but with
 * Model\Ai\OllamaClient fully mocked - it never proves that a REAL unreachable host actually
 * produces the Throwable OllamaClient::generate() catches (Model\Http\JsonApiClient's own real
 * cURL call, not a mock of it). Same "override just the one risky external call" posture as
 * CampaignSendSmsActionTest/CampaignSendEmailActionTest - here the "risky" dependency (a real
 * network call to a self-hosted Ollama instance this environment doesn't have, see ROADMAP.md)
 * is left entirely real, just pointed at an address guaranteed to refuse the connection
 * immediately (127.0.0.1 on a port nothing listens on) rather than a live Ollama instance, so
 * the fail-soft path is exercised for real without needing one.
 *
 * Run from the Magento root: vendor/bin/phpunit --bootstrap app/bootstrap.php
 * vendor/ordo/module-automation/Test/Integration/GenerateAiContentActionTest.php
 */
class GenerateAiContentActionTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    }

    protected function tearDown(): void
    {
        // No <config_data> default for either path, so both are unset/empty unless a test turns
        // them on - leaving them set would leak into every other test run afterward.
        $scopeConfig = self::$objectManager->get(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/ai/enabled', 0, ScopeInterface::SCOPE_STORE);
        $scopeConfig->setValue('ordo_automation/ai/ollama_base_url', '', ScopeInterface::SCOPE_STORE);
    }

    public function testFallsBackToStaticContentWhenAiContentDisabled(): void
    {
        self::$objectManager->get(MutableScopeConfig::class)
            ->setValue('ordo_automation/ai/enabled', 0, ScopeInterface::SCOPE_STORE);

        /** @var GenerateAiContent $action */
        $action = self::$objectManager->create(GenerateAiContent::class);

        $context = [];
        $action->execute($context, [
            'prompt' => 'Write a one-sentence welcome message.',
            'fallback' => 'Welcome back!',
        ]);

        self::assertSame('Welcome back!', $context['ai_content_html']);
    }

    public function testFallsBackToStaticContentWhenOllamaHostRefusesConnection(): void
    {
        $scopeConfig = self::$objectManager->get(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/ai/enabled', 1, ScopeInterface::SCOPE_STORE);
        // Port 1 is a real, resolvable localhost address with nothing bound to it - a genuine
        // connection-refused failure through a real cURL call, not a mocked/stubbed one.
        $scopeConfig->setValue('ordo_automation/ai/ollama_base_url', 'http://127.0.0.1:1', ScopeInterface::SCOPE_STORE);

        /** @var GenerateAiContent $action */
        $action = self::$objectManager->create(GenerateAiContent::class);

        $context = ['customer_first_name' => 'Alex'];
        $action->execute($context, [
            'prompt' => 'Write a one-sentence welcome message for {{customer_first_name}}.',
            'fallback' => 'Welcome back!',
        ]);

        // OllamaClient::generate() must have caught the real connection failure and returned
        // null - the action then falls back, exactly as a live-but-down Ollama instance would.
        self::assertSame('Welcome back!', $context['ai_content_html']);
    }
}
