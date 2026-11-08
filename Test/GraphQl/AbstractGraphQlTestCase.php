<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\GraphQl;

use PHPUnit\Framework\TestCase;

/**
 * Portable GraphQL client for e2e tests - same reasoning as Test\Api\AbstractApiTestCase: plain
 * cURL against a running instance's own /graphql endpoint, no mocks, no dependency on Magento's
 * own dev/tests/api-functional/dev/tests/integration bootstraps (not distributable inside a
 * third-party composer package). Reuses the exact same env vars Test\Api already defines (see
 * that package's README.md and this module's .env.example) so one running instance serves both
 * suites without separate configuration.
 */
abstract class AbstractGraphQlTestCase extends TestCase
{
    private ?string $customerToken = null;

    protected function baseUrl(): string
    {
        $url = getenv('ORDO_API_BASE_URL');
        if (!$url) {
            self::markTestSkipped('ORDO_API_BASE_URL not set — see Test/Api/README.md.');
        }

        return rtrim($url, '/');
    }

    protected function customerToken(): string
    {
        if ($this->customerToken !== null) {
            return $this->customerToken;
        }

        $email = getenv('ORDO_API_CUSTOMER_EMAIL');
        $password = getenv('ORDO_API_CUSTOMER_PASSWORD');
        if (!$email || !$password) {
            self::markTestSkipped('ORDO_API_CUSTOMER_EMAIL/PASSWORD not set — see Test/Api/README.md.');
        }

        $curl = curl_init($this->baseUrl() . '/rest/V1/integration/customer/token');
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['username' => $email, 'password' => $password]),
        ]);
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        self::assertSame(200, $status, 'Customer token request failed: ' . $body);

        return $this->customerToken = (string) json_decode($body, true);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function anonymous(string $query, array $variables = []): array
    {
        return $this->request($query, $variables, null);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function asCustomer(string $query, array $variables = []): array
    {
        return $this->request($query, $variables, $this->customerToken());
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $query, array $variables, ?string $token): array
    {
        $curl = curl_init($this->baseUrl() . '/graphql');

        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables]),
        ]);

        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode($body, true);

        return [$status, is_array($decoded) ? $decoded : []];
    }
}
