<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Adds a real customer tag via this module's own REST API (Api\CustomerTagManagementInterface,
 * PUT /V1/ordo/customers/:customerId/tags/:tag). Authenticates with a real admin OAuth token
 * (POST /V1/integration/admin/token) rather than the admin session cookie other helpers in this
 * suite reuse for HTML-rendering admin controllers - confirmed the hard way that the cookie-based
 * approach reaches this endpoint (a real, ACL-labeled 401) but doesn't carry the same ACL context
 * a real admin session has for this custom "Ordo_Automation::campaigns" resource, unlike the
 * plain HTML admin controllers AdminExportDownloadTestHelper/AdminImportUploadTestHelper call.
 *
 * Exists so a test can put a customer into a real tag-based segment deterministically, between
 * two otherwise-identical dispatches, WITHOUT going through a same-trigger campaign action -
 * CampaignDispatcher::dispatch() evaluates every campaign matching a trigger in one sequential
 * pass, so a same-trigger add_tag campaign's write is already visible to a lower-priority
 * campaign's own condition check within that SAME dispatch (confirmed the hard way: an earlier
 * version of AdminCampaignNotInSegmentConditionTest tried to time the tag via a same-trigger
 * add_tag campaign and got zero coupons on both orders, not one, because the tag was already
 * applied by the time the second campaign's condition ran even on the first order).
 */
class CustomerTagTestHelper extends Helper
{
    public function addTagViaApi(
        string $restBaseUrl,
        int $customerId,
        string $tag,
        string $adminUsername,
        string $adminPassword
    ): void {
        $restBaseUrl = rtrim($restBaseUrl, '/');
        $token = $this->fetchAdminToken($restBaseUrl, $adminUsername, $adminPassword);

        $url = "{$restBaseUrl}/V1/ordo/customers/{$customerId}/tags/" . rawurlencode($tag);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new \RuntimeException("Could not add tag \"{$tag}\" to customer #{$customerId} via {$url} (status {$status}): " . (string) $body);
        }
    }

    /**
     * Reads a tag back via the same REST API addTagViaApi() writes through
     * (Api\CustomerTagManagementInterface::hasTag(), GET /V1/ordo/customers/:customerId/tags/:tag)
     * - used to confirm a cron-written tag (not one this helper itself wrote) actually landed,
     * without depending on a real email round-trip through a downstream digest cron.
     */
    public function hasTagViaApi(
        string $restBaseUrl,
        int $customerId,
        string $tag,
        string $adminUsername,
        string $adminPassword
    ): string {
        $restBaseUrl = rtrim($restBaseUrl, '/');
        $token = $this->fetchAdminToken($restBaseUrl, $adminUsername, $adminPassword);

        $url = "{$restBaseUrl}/V1/ordo/customers/{$customerId}/tags/" . rawurlencode($tag);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new \RuntimeException("Could not read tag \"{$tag}\" for customer #{$customerId} via {$url} (status {$status}): " . (string) $body);
        }

        return trim($body);
    }

    private function fetchAdminToken(string $restBaseUrl, string $username, string $password): string
    {
        $ch = curl_init("{$restBaseUrl}/V1/integration/admin/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $password]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new \RuntimeException("Could not obtain an admin token (status {$status}): " . (string) $body);
        }

        $token = json_decode($body, true);
        if (!is_string($token)) {
            throw new \RuntimeException("Admin token response was not a JSON string: {$body}");
        }

        return $token;
    }
}
