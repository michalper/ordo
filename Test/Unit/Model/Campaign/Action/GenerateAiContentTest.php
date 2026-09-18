<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Ai\OllamaClient;
use Ordo\Automation\Model\Campaign\Action\GenerateAiContent;
use PHPUnit\Framework\TestCase;

class GenerateAiContentTest extends TestCase
{
    private OllamaClient&\PHPUnit\Framework\MockObject\MockObject $ollamaClient;
    private Config&\PHPUnit\Framework\MockObject\Stub $config;
    private GenerateAiContent $action;

    protected function setUp(): void
    {
        $this->ollamaClient = $this->createMock(OllamaClient::class);
        $this->config = $this->createStub(Config::class);
        $this->action = new GenerateAiContent($this->ollamaClient, $this->config);
    }

    public function testFeatureDisabledFallsBackWithoutCallingOllama(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(false);
        $this->ollamaClient->expects(self::never())->method('generate');

        $context = [];
        $this->action->execute($context, ['prompt' => 'write something', 'fallback' => 'static text']);

        self::assertSame('static text', $context['ai_content_html']);
    }

    public function testMissingPromptFallsBackWithoutCallingOllama(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(true);
        $this->ollamaClient->expects(self::never())->method('generate');

        $context = [];
        $this->action->execute($context, ['fallback' => 'static text']);

        self::assertSame('static text', $context['ai_content_html']);
    }

    public function testSuccessfulGenerationWritesToDefaultOutputKey(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(true);
        $this->ollamaClient->expects(self::once())
            ->method('generate')
            ->with('Write a greeting for John')
            ->willReturn('Hi John, welcome back!');

        $context = ['customer_first_name' => 'John'];
        $this->action->execute($context, [
            'prompt' => 'Write a greeting for {{customer_first_name}}',
            'fallback' => 'Welcome back!',
        ]);

        self::assertSame('Hi John, welcome back!', $context['ai_content_html']);
    }

    public function testOllamaFailureFallsBackToStaticText(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(true);
        $this->ollamaClient->expects(self::once())->method('generate')->willReturn(null);

        $context = [];
        $this->action->execute($context, ['prompt' => 'write something', 'fallback' => 'static text']);

        self::assertSame('static text', $context['ai_content_html']);
    }

    public function testUnknownPlaceholderIsLeftLiteral(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(true);
        $this->ollamaClient->expects(self::once())
            ->method('generate')
            ->with('Hello {{unknown_key}}')
            ->willReturn('generated');

        $context = [];
        $this->action->execute($context, ['prompt' => 'Hello {{unknown_key}}']);

        self::assertSame('generated', $context['ai_content_html']);
    }

    public function testCustomOutputKeyIsRespected(): void
    {
        $this->config->method('isAiContentEnabled')->willReturn(true);
        $this->ollamaClient->expects(self::once())->method('generate')->willReturn('generated');

        $context = [];
        $this->action->execute($context, ['prompt' => 'x', 'output_key' => 'custom_key']);

        self::assertSame('generated', $context['custom_key']);
        self::assertArrayNotHasKey('ai_content_html', $context);
    }
}
