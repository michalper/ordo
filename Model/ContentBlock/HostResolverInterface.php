<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

/**
 * Thin seam around DNS resolution, injectable purely so RssFetcher's SSRF check (resolve once,
 * validate every candidate, pin the connection to it) can be unit tested without a real network
 * lookup - see RssFetcher's own docblock on why that resolve step exists at all.
 */
interface HostResolverInterface
{
    /**
     * @return string[] every resolved IPv4/IPv6 address, or an empty array if resolution failed
     */
    public function resolve(string $host): array;
}
