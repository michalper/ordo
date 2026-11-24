<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeValue;
use Ordo\Automation\Plugin\Customer\ProtectAdminOnlyCustomerAttributesPlugin;
use Ordo\Automation\Test\Unit\Model\AdminActionLog\BackendAuthSessionTestDouble;
use Ordo\Automation\Test\Unit\Model\AdminActionLog\UserTestDouble;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ProtectAdminOnlyCustomerAttributesPluginTest extends TestCase
{
    private function makeAttribute(string $code, mixed $value): AttributeValue
    {
        $attribute = $this->createStub(AttributeValue::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getValue')->willReturn($value);

        return $attribute;
    }

    /**
     * Customer A, with no admin session at all (the real exploit path: Magento core's own
     * PUT /V1/customers/me), can't zero out their own credit/spend limit to defeat
     * Plugin\Quote\BlockOverLimitCheckout/Observer\HoldOrderForApproval.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testBeforeSaveStripsTheProtectedAttributesWhenNoAdminIsLoggedIn(): void
    {
        $authSession = new BackendAuthSessionTestDouble();

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getCustomAttributes')->willReturn([
            $this->makeAttribute('ordo_credit_limit', '0'),
            $this->makeAttribute('ordo_order_spend_limit', '0'),
            $this->makeAttribute('ordo_approval_admin_email', ''),
            $this->makeAttribute('some_other_attribute', 'kept'),
        ]);
        $customer->expects(self::once())->method('setCustomAttributes')->with(
            self::callback(static function (array $attributes): bool {
                self::assertCount(1, $attributes);
                return $attributes[0]->getAttributeCode() === 'some_other_attribute';
            })
        );

        $plugin = new ProtectAdminOnlyCustomerAttributesPlugin($authSession);

        self::assertSame(
            [$customer, 'irrelevant'],
            $plugin->beforeSave($this->createStub(CustomerRepositoryInterface::class), $customer, 'irrelevant')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBeforeSaveLeavesTheCustomerUntouchedWhenAnAdminIsLoggedIn(): void
    {
        $authSession = (new BackendAuthSessionTestDouble())->setTestUser(new UserTestDouble());

        $customer = $this->createMock(CustomerInterface::class);
        $customer->expects(self::never())->method('getCustomAttributes');
        $customer->expects(self::never())->method('setCustomAttributes');

        $plugin = new ProtectAdminOnlyCustomerAttributesPlugin($authSession);

        self::assertSame(
            [$customer, null],
            $plugin->beforeSave($this->createStub(CustomerRepositoryInterface::class), $customer)
        );
    }
}
