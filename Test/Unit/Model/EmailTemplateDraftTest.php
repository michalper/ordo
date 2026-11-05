<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\EmailTemplateDraft;

class EmailTemplateDraftTest extends AbstractModelTestCase
{
    private function makeModel(): EmailTemplateDraft
    {
        return new EmailTemplateDraft($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testTemplateIdRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTemplateId(7);

        self::assertSame(7, $model->getTemplateId());
    }

    public function testTemplateCodeRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTemplateCode('welcome_email');

        self::assertSame('welcome_email', $model->getTemplateCode());
    }

    public function testTemplateSubjectRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTemplateSubject('Welcome!');

        self::assertSame('Welcome!', $model->getTemplateSubject());
    }

    public function testTemplateTextRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTemplateText('<p>Hello {{var customer_name}}</p>');

        self::assertSame('<p>Hello {{var customer_name}}</p>', $model->getTemplateText());
    }

    public function testTemplateStylesIsNullWhenNeverSet(): void
    {
        self::assertNull($this->makeModel()->getTemplateStyles());
    }

    public function testTemplateStylesRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setTemplateStyles('.a { color: red; }');

        self::assertSame('.a { color: red; }', $model->getTemplateStyles());
    }

    public function testTemplateStylesCanBeSetBackToNull(): void
    {
        $model = $this->makeModel();
        $model->setTemplateStyles('.a { color: red; }');
        $model->setTemplateStyles(null);

        self::assertNull($model->getTemplateStyles());
    }

    public function testUpdatedAtRoundTrip(): void
    {
        $model = $this->makeModel();
        $model->setData('updated_at', '2026-01-01 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getData('updated_at'));
    }
}
