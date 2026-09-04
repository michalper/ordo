<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\Delete;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DeleteTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerResource = $this->createStub(FreeGiftOfferResource::class);

        $controller = new Delete($context, $offerFactory, $offerResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $offer = $this->createStub(FreeGiftOffer::class);
        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerFactory->method('create')->willReturn($offer);

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::once())->method('delete')->with($offer);

        $controller = new Delete($context, $offerFactory, $offerResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $offer = $this->createStub(FreeGiftOffer::class);
        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerFactory->method('create')->willReturn($offer);

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $offerFactory, $offerResource);
        self::assertSame($redirect, $controller->execute());
    }
}
