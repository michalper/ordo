<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Referral;

class ReferralTest extends AbstractModelTestCase
{
    private function makeModel(): Referral
    {
        return new Referral($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testReferrerCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setReferrerCustomerId(5);

        self::assertSame(5, $model->getReferrerCustomerId());
    }

    public function testReferredCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setReferredCustomerId(9);

        self::assertSame(9, $model->getReferredCustomerId());
    }

    public function testStatusRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setStatus(Referral::STATUS_CONVERTED);

        self::assertSame(Referral::STATUS_CONVERTED, $model->getStatus());
    }

    public function testDefaultsToPendingConstantValue(): void
    {
        self::assertSame('pending', Referral::STATUS_PENDING);
        self::assertSame('converted', Referral::STATUS_CONVERTED);
    }
}
