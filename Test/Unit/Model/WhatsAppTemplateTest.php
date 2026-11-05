<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\WhatsAppTemplate;

class WhatsAppTemplateTest extends AbstractModelTestCase
{
    private function makeModel(): WhatsAppTemplate
    {
        return new WhatsAppTemplate($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testNameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setName('Order Shipped');
        self::assertSame('Order Shipped', $model->getName());
    }

    public function testMetaTemplateNameRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setMetaTemplateName('order_shipped_v1');
        self::assertSame('order_shipped_v1', $model->getMetaTemplateName());
    }

    public function testCategoryRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setCategory(WhatsAppTemplate::CATEGORY_UTILITY);
        self::assertSame('utility', $model->getCategory());
    }

    public function testLanguageRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setLanguage('pl_PL');
        self::assertSame('pl_PL', $model->getLanguage());
    }

    public function testBodyTextRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setBodyText('Hi {{1}}, your order {{2}} shipped');
        self::assertSame('Hi {{1}}, your order {{2}} shipped', $model->getBodyText());
    }

    public function testPlaceholderLegendRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getPlaceholderLegend());

        $model->setPlaceholderLegend('{{1}} = first name, {{2}} = order number');
        self::assertSame('{{1}} = first name, {{2}} = order number', $model->getPlaceholderLegend());

        $model->setPlaceholderLegend(null);
        self::assertNull($model->getPlaceholderLegend());
    }

    public function testMetaTemplateIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getMetaTemplateId());

        $model->setMetaTemplateId('123456789');
        self::assertSame('123456789', $model->getMetaTemplateId());

        $model->setMetaTemplateId(null);
        self::assertNull($model->getMetaTemplateId());
    }

    public function testStatusRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setStatus(WhatsAppTemplate::STATUS_PENDING);
        self::assertSame('pending', $model->getStatus());
    }

    public function testIsApprovedReflectsStatus(): void
    {
        $model = $this->makeModel();
        $model->setStatus(WhatsAppTemplate::STATUS_PENDING);
        self::assertFalse($model->isApproved());

        $model->setStatus(WhatsAppTemplate::STATUS_APPROVED);
        self::assertTrue($model->isApproved());
    }

    public function testRejectionReasonRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getRejectionReason());

        $model->setRejectionReason('Body text violates commerce policy');
        self::assertSame('Body text violates commerce policy', $model->getRejectionReason());
    }

    public function testSubmittedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getSubmittedAt());

        $model->setSubmittedAt('2026-01-01 00:00:00');
        self::assertSame('2026-01-01 00:00:00', $model->getSubmittedAt());
    }
}
