<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Requires ORDO_API_CUSTOMER_EMAIL/PASSWORD to resolve to customer id 1 (same standing
 * assumption as OfferApiTest/CustomerTagManagementApiTest) so testGetMyStatusAsCustomer can be
 * compared directly against the admin by-id lookup for the same customer.
 */
class CreditLimitApiTest extends AbstractApiTestCase
{
    public function testGetStatusForCustomerAsAdmin(): void
    {
        [$status, $body] = $this->asAdmin('GET', '/rest/V1/ordo/customers/1/credit-limit');

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayHasKey('credit_limit', $body);
        self::assertArrayHasKey('used_credit', $body);
        self::assertArrayHasKey('available_credit', $body);
        self::assertArrayHasKey('utilization_percent', $body);
        self::assertEqualsWithDelta(
            $body['credit_limit'] - $body['used_credit'],
            $body['available_credit'],
            0.001,
            'available_credit must equal credit_limit - used_credit'
        );
    }

    public function testGetStatusForCustomerReturns404ForNonexistentCustomer(): void
    {
        [$status] = $this->asAdmin('GET', '/rest/V1/ordo/customers/999999/credit-limit');

        self::assertSame(404, $status);
    }

    public function testGetStatusForCustomerRequiresAdminAcl(): void
    {
        // Magento's WebAPI framework reports an ACL-insufficient token as 401 (not 403) —
        // it treats "wrong token type/scope for this resource" the same as "no token at all".
        [$status] = $this->asCustomer('GET', '/rest/V1/ordo/customers/1/credit-limit');

        self::assertSame(401, $status);
    }

    public function testGetStatusForCustomerRequiresAuthentication(): void
    {
        [$status] = $this->anonymous('GET', '/rest/V1/ordo/customers/1/credit-limit');

        self::assertSame(401, $status);
    }

    public function testGetMyStatusAsCustomerMatchesAdminLookupForTheSameCustomer(): void
    {
        [$adminStatus, $adminBody] = $this->asAdmin('GET', '/rest/V1/ordo/customers/1/credit-limit');
        self::assertSame(200, $adminStatus);

        [$status, $body] = $this->asCustomer('GET', '/rest/V1/ordo/credit-limit/mine');

        self::assertSame(200, $status, json_encode($body));
        self::assertSame($adminBody['credit_limit'], $body['credit_limit']);
        self::assertSame($adminBody['used_credit'], $body['used_credit']);
        self::assertSame($adminBody['available_credit'], $body['available_credit']);
        self::assertSame($adminBody['utilization_percent'], $body['utilization_percent']);
    }

    public function testGetMyStatusRequiresAuthentication(): void
    {
        [$status] = $this->anonymous('GET', '/rest/V1/ordo/credit-limit/mine');

        self::assertSame(401, $status);
    }
}
