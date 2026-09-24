<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Reads the real assigned-rep customer attribute (AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL)
 * back via Magento's own core REST API (GET /V1/customers/search, filtered by email), the same
 * "read via REST instead of a second real round trip" technique CustomerTagTestHelper already
 * established for this module's own custom tag endpoint - here it's Magento core's endpoint
 * since ordo_sales_rep_email is a plain customer EAV attribute, not something this module
 * exposes its own API for. Looked up by email rather than id for the same reason
 * AdminCampaignCustomerRegisteredTriggerTest's own VisitorEventHelper call is - a real storefront
 * registration leaves no entity_id available to the test.
 */
class LeadRoutingRuleTestHelper extends Helper
{
    private const string ATTRIBUTE_REP_EMAIL = 'ordo_sales_rep_email';

    public function getAssignedRepEmailByCustomerEmail(
        string $restBaseUrl,
        string $customerEmail,
        string $adminUsername,
        string $adminPassword
    ): string {
        $restBaseUrl = rtrim($restBaseUrl, '/');
        $token = $this->fetchAdminToken($restBaseUrl, $adminUsername, $adminPassword);

        $query = http_build_query([
            'searchCriteria' => [
                'filter_groups' => [
                    [
                        'filters' => [
                            ['field' => 'email', 'value' => $customerEmail, 'condition_type' => 'eq'],
                        ],
                    ],
                ],
            ],
        ]);
        $url = "{$restBaseUrl}/V1/customers/search?{$query}";

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
            throw new \RuntimeException("Could not search for customer \"{$customerEmail}\" via {$url} (status {$status}): " . (string) $body);
        }

        $decoded = json_decode($body, true);
        $items = is_array($decoded) ? ($decoded['items'] ?? []) : [];
        if (!isset($items[0])) {
            throw new \RuntimeException("No customer found for email \"{$customerEmail}\" via {$url}: {$body}");
        }

        $customAttributes = $items[0]['custom_attributes'] ?? [];
        foreach ($customAttributes as $attribute) {
            if (($attribute['attribute_code'] ?? null) === self::ATTRIBUTE_REP_EMAIL) {
                return (string) ($attribute['value'] ?? '');
            }
        }

        return '';
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
