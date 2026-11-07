<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

/**
 * Real implementation of HostResolverInterface - a thin wrapper around gethostbynamel() (IPv4
 * only; this module has no IPv6-specific handling anywhere else either) so RssFetcher's own SSRF
 * check can inject a fake resolver in tests instead of making a real DNS lookup.
 */
class HostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : $addresses;
    }
}
