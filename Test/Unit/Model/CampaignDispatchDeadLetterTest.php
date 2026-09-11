<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\CampaignDispatchDeadLetter;

class CampaignDispatchDeadLetterTest extends AbstractModelTestCase
{
    private function makeModel(): CampaignDispatchDeadLetter
    {
        return new CampaignDispatchDeadLetter(
            $this->makeModelContext(),
            $this->makeRegistry(),
            $this->makeModelResource()
        );
    }

    public function testTriggerEventRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTriggerEvent('order_placed');

        self::assertSame('order_placed', $model->getData(CampaignDispatchDeadLetter::TRIGGER_EVENT));
    }

    public function testTriggerEventAcceptsNull(): void
    {
        $model = $this->makeModel();
        $model->setTriggerEvent(null);

        self::assertNull($model->getData(CampaignDispatchDeadLetter::TRIGGER_EVENT));
    }

    public function testMessageRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setMessage('{"malformed":');

        self::assertSame('{"malformed":', $model->getData(CampaignDispatchDeadLetter::MESSAGE));
    }

    public function testErrorRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setError('Unknown condition type "bogus".');

        self::assertSame('Unknown condition type "bogus".', $model->getData(CampaignDispatchDeadLetter::ERROR));
    }
}
