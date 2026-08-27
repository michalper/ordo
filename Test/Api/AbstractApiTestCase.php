<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

use PHPUnit\Framework\TestCase;

/**
 * Portable REST client for API-functional tests — no dependency on Magento's own
 * dev/tests/api-functional bootstrap (not distributable inside a third-party composer
 * package), just plain cURL against a running instance. See README.md for why.
 */
abstract class AbstractApiTestCase extends TestCase
{
    private ?string $adminToken = null;
    private ?string $customerToken = null;

    protected function baseUrl(): string
    {
        $url = getenv('ORDO_API_BASE_URL');
        if (!$url) {
            self::markTestSkipped('ORDO_API_BASE_URL not set — see Test/Api/README.md.');
        }

        return rtrim($url, '/');
    }

    protected function adminToken(): string
    {
        if ($this->adminToken !== null) {
            return $this->adminToken;
        }

        $username = getenv('ORDO_API_ADMIN_USERNAME');
        $password = getenv('ORDO_API_ADMIN_PASSWORD');
        if (!$username || !$password) {
            self::markTestSkipped('ORDO_API_ADMIN_USERNAME/PASSWORD not set — see Test/Api/README.md.');
        }

        [$status, $body] = $this->rawRequest('POST', '/rest/V1/integration/admin/token', null, [
            'username' => $username,
            'password' => $password,
        ]);

        self::assertSame(200, $status, 'Admin token request failed: ' . $body);

        return $this->adminToken = json_decode($body, true);
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

        [$status, $body] = $this->rawRequest('POST', '/rest/V1/integration/customer/token', null, [
            'username' => $email,
            'password' => $password,
        ]);

        self::assertSame(200, $status, 'Customer token request failed: ' . $body);

        return $this->customerToken = json_decode($body, true);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function asAdmin(string $method, string $path, ?array $payload = null): array
    {
        return $this->request($method, $path, $this->adminToken(), $payload);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function asCustomer(string $method, string $path, ?array $payload = null): array
    {
        return $this->request($method, $path, $this->customerToken(), $payload);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function anonymous(string $method, string $path, ?array $payload = null): array
    {
        return $this->request($method, $path, null, $payload);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    private function request(string $method, string $path, ?string $token, ?array $payload): array
    {
        [$status, $body] = $this->rawRequest($method, $path, $token, $payload);
        $decoded = $body !== '' ? json_decode($body, true) : null;

        return [$status, $decoded];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function rawRequest(string $method, string $path, ?string $token, ?array $payload): array
    {
        $curl = curl_init($this->baseUrl() . $path);

        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload !== null ? json_encode($payload) : null,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [$status, (string) $body];
    }
}
