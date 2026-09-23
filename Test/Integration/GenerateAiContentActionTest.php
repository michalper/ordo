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

    public function testFallsBackToStaticContentWhenAiContentDisabled(): void
    {
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
        // Two real CI runs (diagnostic-instrumented, on the sibling CampaignSendSmsActionTest)
        // proved $objectManager->get(MutableScopeConfig::class)->setValue(...) does NOT affect
        // what a class's own injected ScopeConfigInterface sees in this install - they resolve
        // to two genuinely different object instances. The only way that's proven to actually
        // work is constructing the SAME MutableScopeConfig instance and passing it explicitly
        // as every affected class's own scopeConfig constructor argument - here that's BOTH
        // OllamaClient (reads the base URL) and GenerateAiContent (reads the enabled flag), so a
        // single shared Config instance built on that same MutableScopeConfig is injected into
        // both.
        $scopeConfig = self::$objectManager->create(MutableScopeConfig::class);
        $scopeConfig->setValue('ordo_automation/ai/enabled', 1, ScopeInterface::SCOPE_STORE);
        // Port 1 is a real, resolvable localhost address with nothing bound to it - a genuine
        // connection-refused failure through a real cURL call, not a mocked/stubbed one.
        $scopeConfig->setValue('ordo_automation/ai/ollama_base_url', 'http://127.0.0.1:1', ScopeInterface::SCOPE_STORE);

        $config = self::$objectManager->create(\Ordo\Automation\Helper\Config::class, [
            'scopeConfig' => $scopeConfig,
        ]);
        $ollamaClient = self::$objectManager->create(\Ordo\Automation\Model\Ai\OllamaClient::class, [
            'config' => $config,
        ]);

        /** @var GenerateAiContent $action */
        $action = self::$objectManager->create(GenerateAiContent::class, [
            'config' => $config,
            'ollamaClient' => $ollamaClient,
        ]);

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
