<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\App\Router;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Ordo\Automation\App\Router\WellKnownRouter;
use Ordo\Automation\Controller\WellKnown\AiPluginManifest;
use PHPUnit\Framework\TestCase;

class WellKnownRouterTest extends TestCase
{
    private AiPluginManifest $manifestAction;
    private WellKnownRouter $router;

    protected function setUp(): void
    {
        $this->manifestAction = $this->createStub(AiPluginManifest::class);
        $this->router = new WellKnownRouter($this->manifestAction);
    }

    public function testMatchReturnsNullForNonHttpRequest(): void
    {
        $request = $this->createStub(RequestInterface::class);

        self::assertNull($this->router->match($request));
    }

    public function testMatchReturnsNullForAnUnrelatedPath(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getPathInfo')->willReturn('/ordo/productfeed/index');

        self::assertNull($this->router->match($request));
    }

    public function testMatchDispatchesTheManifestActionForTheWellKnownPath(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn('/.well-known/ai-plugin.json');
        $request->expects(self::once())->method('setDispatched')->with(true);

        self::assertSame($this->manifestAction, $this->router->match($request));
    }
}
