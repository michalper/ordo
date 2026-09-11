<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ProductFeed;

use Ordo\Automation\Model\ProductFeed\ProductFeedRunLog;
use Ordo\Automation\Test\Unit\Model\AbstractModelTestCase;

class ProductFeedRunLogTest extends AbstractModelTestCase
{
    private function makeModel(): ProductFeedRunLog
    {
        return new ProductFeedRunLog($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testFeedCodeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setFeedCode('google_merchant');

        self::assertSame('google_merchant', $model->getData('feed_code'));
    }

    public function testStoreIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setStoreId(2);

        self::assertSame(2, $model->getData('store_id'));
    }

    public function testStatusRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setStatus(ProductFeedRunLog::STATUS_SUCCESS);

        self::assertSame('success', $model->getData('status'));
    }

    public function testProductCountRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setProductCount(120);

        self::assertSame(120, $model->getData('product_count'));
    }

    public function testMessageRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setMessage('OpenSearch timed out');

        self::assertSame('OpenSearch timed out', $model->getData('message'));
    }

    public function testMessageAcceptsNull(): void
    {
        $model = $this->makeModel();
        $model->setMessage(null);

        self::assertNull($model->getData('message'));
    }
}
