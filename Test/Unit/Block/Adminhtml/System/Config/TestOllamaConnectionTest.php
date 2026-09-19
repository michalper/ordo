<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Ordo\Automation\Block\Adminhtml\System\Config\TestOllamaConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Backend\Block\Template's own constructor (Field's parent) falls back to
 * ObjectManager::getInstance()->get(...) for jsonHelper/directoryHelper/SecureHtmlRenderer
 * whenever a subclass doesn't forward them explicitly - which TestOllamaConnection doesn't.
 * Stubbing the global ObjectManager singleton for the duration of this test (and restoring it in
 * tearDown) is the same technique AudienceSizeTest/BulkActionsTest use for the equivalent problem
 * there.
 *
 * render()/_getElementHtml() go through real Magento core (Field::render(),
 * Backend\Block\Template::_toHtml()) rather than being re-mocked away: passing an explicit empty
 * `template` in $data makes Template::_toHtml()'s own `if (!$this->getTemplate()) return '';`
 * short-circuit before any real filesystem/theme-resolution code runs, so the whole real render()
 * chain executes safely without needing a full app bootstrap - it just never gets far enough to
 * touch a template file. $element is a real (constructor-disabled) AbstractElement rather than a
 * fully-mocked one specifically so its magic uns*()/getData()/setData() (Field::render() reads
 * several element getters that are pure DataObject magic, not real declared methods) keep working
 * for real; only getHtmlId() - which needs a real Form to avoid a null-pointer - is faked.
 */
class TestOllamaConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($this->createStub(\stdClass::class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    private function makeContext(): Context
    {
        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnMap([
            ['ordo/ai/testconnection', [], 'https://example.com/admin/ordo/ai/testconnection/'],
        ]);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getEventManager')->willReturn($this->createStub(ManagerInterface::class));

        return $context;
    }

    public function testGetAjaxUrlBuildsTheTestConnectionControllerUrl(): void
    {
        $block = new TestOllamaConnection($this->makeContext());

        self::assertSame(
            'https://example.com/admin/ordo/ai/testconnection/',
            $block->getAjaxUrl()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRenderStripsScopeOverridesAndReturnsARealFormRow(): void
    {
        $element = $this->getMockBuilder(AbstractElement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getHtmlId'])
            ->getMock();
        $element->method('getHtmlId')->willReturn('ordo_ai_test_connection');
        // Real (unmocked) magic setter - proves unsScope()/unsCanUseWebsiteValue()/
        // unsCanUseDefaultValue() actually ran, not just that render() didn't throw.
        $element->setData('scope', 'websites');
        $element->setData('can_use_website_value', true);
        $element->setData('can_use_default_value', true);

        $block = new TestOllamaConnection($this->makeContext(), ['template' => '']);

        $html = $block->render($element);

        self::assertStringContainsString('row_ordo_ai_test_connection', $html);
        self::assertNull($element->getScope());
        self::assertNull($element->getCanUseWebsiteValue());
        self::assertNull($element->getCanUseDefaultValue());
    }
}
