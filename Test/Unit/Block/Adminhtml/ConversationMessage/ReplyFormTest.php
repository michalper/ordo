<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\ConversationMessage;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\ConversationMessage\ReplyForm;
use PHPUnit\Framework\TestCase;

/**
 * Same ObjectManager-stubbing technique as Test\Unit\Block\Adminhtml\Import\FormTest -
 * Template's own constructor falls back to ObjectManager::getInstance()->get(...) for
 * jsonHelper/directoryHelper whenever a subclass doesn't forward them explicitly.
 */
class ReplyFormTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    private function makeBlock(?int $replyToParam): ReplyForm
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            fn (string $path): string => 'https://example.test/admin/' . $path
        );

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($replyToParam);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getRequest')->willReturn($request);

        return new ReplyForm($context);
    }

    public function testGetReplyUrlPointsAtTheReplyController(): void
    {
        $block = $this->makeBlock(null);

        self::assertSame('https://example.test/admin/ordo/conversationmessage/reply', $block->getReplyUrl());
    }

    public function testGetPrefilledEntityIdReadsTheReplyToQueryParam(): void
    {
        $block = $this->makeBlock(5);

        self::assertSame(5, $block->getPrefilledEntityId());
    }

    public function testGetPrefilledEntityIdDefaultsToZeroWhenParamMissing(): void
    {
        $block = $this->makeBlock(null);

        self::assertSame(0, $block->getPrefilledEntityId());
    }
}
