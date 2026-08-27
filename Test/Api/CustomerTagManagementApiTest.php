<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

class CustomerTagManagementApiTest extends AbstractApiTestCase
{
    public function testAddQueryAndRemoveTagRoundTrip(): void
    {
        $tag = 'api-test-tag-' . uniqid();

        [$status] = $this->asAdmin('PUT', "/rest/V1/ordo/customers/1/tags/{$tag}");
        self::assertSame(200, $status);

        [$status, $tags] = $this->asAdmin('GET', '/rest/V1/ordo/customers/1/tags');
        self::assertSame(200, $status);
        self::assertContains($tag, $tags);

        [$status, $hasTag] = $this->asAdmin('GET', "/rest/V1/ordo/customers/1/tags/{$tag}");
        self::assertSame(200, $status);
        self::assertTrue($hasTag);

        [$status, $customerIds] = $this->asAdmin('GET', "/rest/V1/ordo/tags/{$tag}/customers");
        self::assertSame(200, $status);
        self::assertContains(1, $customerIds);

        [$status] = $this->asAdmin('DELETE', "/rest/V1/ordo/customers/1/tags/{$tag}");
        self::assertSame(200, $status);

        [, $hasTagAfterRemoval] = $this->asAdmin('GET', "/rest/V1/ordo/customers/1/tags/{$tag}");
        self::assertFalse($hasTagAfterRemoval);
    }
}
