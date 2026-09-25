<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Email;

use Magento\Email\Model\ResourceModel\Template as MagentoTemplateResource;
use Magento\Email\Model\Template as MagentoTemplate;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\EmailTemplateVersion;
use Ordo\Automation\Model\EmailTemplateVersionFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateVersion as EmailTemplateVersionResource;
use Ordo\Automation\Plugin\Email\SnapshotEmailTemplateVersion;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SnapshotEmailTemplateVersionTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAfterSaveSnapshotsTheJustSavedTemplate(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailTemplateVersioningEnabled')->willReturn(true);

        $model = $this->createStub(MagentoTemplate::class);
        $model->method('getId')->willReturn(7);
        $model->method('getData')->willReturnCallback(static fn (string $key): ?string => match ($key) {
            'template_code' => 'welcome_email',
            'template_subject' => 'Welcome!',
            'template_text' => '<p>Hi</p>',
            'template_styles' => '.a { color: red; }',
            default => null,
        });

        $version = $this->createMock(EmailTemplateVersion::class);
        $version->expects(self::once())->method('setTemplateId')->with(7);
        $version->expects(self::once())->method('setTemplateCode')->with('welcome_email');
        $version->expects(self::once())->method('setTemplateSubject')->with('Welcome!');
        $version->expects(self::once())->method('setTemplateText')->with('<p>Hi</p>');
        $version->expects(self::once())->method('setTemplateStyles')->with('.a { color: red; }');

        $versionFactory = $this->createStub(EmailTemplateVersionFactory::class);
        $versionFactory->method('create')->willReturn($version);

        $versionResource = $this->createMock(EmailTemplateVersionResource::class);
        $versionResource->expects(self::once())->method('save')->with($version);

        $subject = $this->createStub(MagentoTemplateResource::class);
        $result = $this->createStub(MagentoTemplateResource::class);

        $plugin = new SnapshotEmailTemplateVersion($config, $versionFactory, $versionResource);
        self::assertSame($result, $plugin->afterSave($subject, $result, $model));
    }

    public function testAfterSaveSkipsSnapshotWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEmailTemplateVersioningEnabled')->willReturn(false);

        $versionFactory = $this->createMock(EmailTemplateVersionFactory::class);
        $versionFactory->expects(self::never())->method('create');

        $versionResource = $this->createMock(EmailTemplateVersionResource::class);
        $versionResource->expects(self::never())->method('save');

        $subject = $this->createStub(MagentoTemplateResource::class);
        $result = $this->createStub(MagentoTemplateResource::class);
        $model = $this->createStub(MagentoTemplate::class);

        $plugin = new SnapshotEmailTemplateVersion($config, $versionFactory, $versionResource);
        self::assertSame($result, $plugin->afterSave($subject, $result, $model));
    }
}
