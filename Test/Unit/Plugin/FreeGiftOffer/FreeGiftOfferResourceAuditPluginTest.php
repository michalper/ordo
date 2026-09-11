<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\FreeGiftOffer;

use Ordo\Automation\Model\AdminActionLog;
use Ordo\Automation\Model\AdminActionLog\Recorder;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Plugin\FreeGiftOffer\FreeGiftOfferResourceAuditPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class FreeGiftOfferResourceAuditPluginTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveSkipsRecordingWithNoLoggedInAdmin(): void
    {
        $model = $this->createStub(FreeGiftOffer::class);
        $subject = $this->createStub(FreeGiftOfferResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(false);
        $recorder->expects(self::never())->method('record');

        $plugin = new FreeGiftOfferResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsCreateWithNoDiffWhenModelHasNoId(): void
    {
        $model = $this->createStub(FreeGiftOffer::class);
        $model->method('getId')->willReturn(null);
        $subject = $this->createStub(FreeGiftOfferResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::never())->method('diffFields');
        $recorder->expects(self::once())->method('record')
            ->with('free_gift_offer', 0, AdminActionLog::ACTION_CREATE, null);

        $plugin = new FreeGiftOfferResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAroundSaveRecordsUpdateWithDiffWhenModelAlreadyHasAnId(): void
    {
        $model = $this->createStub(FreeGiftOffer::class);
        $model->method('getId')->willReturn(7);
        $subject = $this->createStub(FreeGiftOfferResource::class);

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('hasLoggedInAdmin')->willReturn(true);
        $recorder->expects(self::once())->method('diffFields')
            ->with($model, ['name', 'enabled'])
            ->willReturn(['name' => ['Old', 'New']]);
        $recorder->expects(self::once())->method('record')
            ->with('free_gift_offer', 7, AdminActionLog::ACTION_UPDATE, ['name' => ['Old', 'New']]);

        $plugin = new FreeGiftOfferResourceAuditPlugin($recorder);
        $plugin->aroundSave($subject, fn () => $subject, $model);
    }
}
