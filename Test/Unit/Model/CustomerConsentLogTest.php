<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\CustomerConsentLog;

class CustomerConsentLogTest extends AbstractModelTestCase
{
    private function makeModel(): CustomerConsentLog
    {
        return new CustomerConsentLog($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testCustomerIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCustomerId(42);
        self::assertSame(42, $model->getCustomerId());
    }

    public function testChannelRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setChannel(ConsentChannel::Email->value);
        self::assertSame('email', $model->getChannel());
    }

    public function testConsentedRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertFalse($model->isConsented());

        $model->setConsented(true);
        self::assertTrue($model->isConsented());
    }

    public function testSourceRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getSource());

        $model->setSource('admin');
        self::assertSame('admin', $model->getSource());

        $model->setSource(null);
        self::assertNull($model->getSource());
    }

    public function testGetCreatedAtReturnsNullWhenUnset(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getCreatedAt());
    }
}
