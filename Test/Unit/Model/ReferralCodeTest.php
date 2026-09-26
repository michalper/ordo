<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ReferralCode;

class ReferralCodeTest extends AbstractModelTestCase
{
    private function makeModel(): ReferralCode
    {
        return new ReferralCode($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(12);

        self::assertSame(12, $model->getCustomerId());
    }

    public function testCodeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCode('ABCD1234');

        self::assertSame('ABCD1234', $model->getCode());
    }
}
