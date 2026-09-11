<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\MessageSendRetry;

class MessageSendRetryTest extends AbstractModelTestCase
{
    private function makeModel(): MessageSendRetry
    {
        return new MessageSendRetry($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testActionTypeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setActionType('send_email');

        self::assertSame('send_email', $model->getActionType());
    }

    public function testContextRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setContext(['customer_id' => 7, 'campaign_id' => 3]);

        self::assertSame(['customer_id' => 7, 'campaign_id' => 3], $model->getContext());
    }

    public function testGetContextReturnsEmptyArrayWhenNeverSet(): void
    {
        $model = $this->makeModel();

        self::assertSame([], $model->getContext());
    }

    public function testParamsRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setParams(['template' => 'ordo_campaign_generic']);

        self::assertSame(['template' => 'ordo_campaign_generic'], $model->getParams());
    }

    public function testGetParamsReturnsEmptyArrayWhenNeverSet(): void
    {
        $model = $this->makeModel();

        self::assertSame([], $model->getParams());
    }

    public function testAttemptsRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setAttempts(2);

        self::assertSame(2, $model->getAttempts());
    }

    public function testLastErrorRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLastError('SMTP is down');

        self::assertSame('SMTP is down', $model->getLastError());
    }

    public function testLastErrorDefaultsToNull(): void
    {
        $model = $this->makeModel();

        self::assertNull($model->getLastError());
    }

    public function testNextRetryAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setNextRetryAt('2026-01-01 00:05:00');

        self::assertSame('2026-01-01 00:05:00', $model->getNextRetryAt());
    }
}
