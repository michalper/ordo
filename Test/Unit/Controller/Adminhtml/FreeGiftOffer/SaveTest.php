<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\Save;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOffer\FreeGiftOfferSaveProcessor;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private FreeGiftOfferSaveProcessor $saveProcessor;

    protected function setUp(): void
    {
        $this->saveProcessor = $this->createMock(FreeGiftOfferSaveProcessor::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->saveProcessor
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->saveProcessor->expects(self::never())->method('process');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewOfferWithTiersAndProducts(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'name' => 'Spend more, get more',
            'enabled' => '1',
            'tiers' => ['tiers' => [['min_subtotal' => '100', 'gift_slots' => '1']]],
            'products' => ['products' => [['sku' => 'GIFT-MUG']]],
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(7);
        $this->saveProcessor->expects(self::once())->method('process')->with($postData)->willReturn($offer);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $postData = ['name' => 'Spend more, get more'];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(7);
        $this->saveProcessor->method('process')->with($postData)->willReturn($offer);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'Spend more, get more']);

        $this->saveProcessor->method('process')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }
}
