<?php
declare(strict_types=1);

namespace Ordo\Automation\App\Router;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Ordo\Automation\Controller\WellKnown\AiPluginManifest;

/**
 * Matches the fixed "/.well-known/ai-plugin.json" path to Controller\WellKnown\AiPluginManifest.
 * Magento's standard router only ever matches "<frontName>/<controller>/<action>"-shaped paths
 * (see etc/frontend/routes.xml's "ordo" frontName for every other public controller in this
 * module), so a path with no frontName segment - the ".well-known" discovery convention every
 * AI-agent/OAuth/ACME tool already expects - needs its own RouterInterface, registered into
 * Magento\Framework\App\RouterList via etc/frontend/di.xml. This is the first and only precedent
 * for a fixed top-level path in this module.
 */
class WellKnownRouter implements RouterInterface
{
    private const string MATCHED_PATH = '/.well-known/ai-plugin.json';

    public function __construct(
        private readonly AiPluginManifest $aiPluginManifest
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest || $request->getPathInfo() !== self::MATCHED_PATH) {
            return null;
        }

        $request->setDispatched(true);

        return $this->aiPluginManifest;
    }
}
