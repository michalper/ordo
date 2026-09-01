<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\Edit;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\FreeGiftOfferFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EditTest extends AbstractAdminActionTestCase
{
    private function makeResultPage(string $expectedTitle): Page
    {
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(self::callback(
            fn ($phrase) => (string) $phrase === $expectedTitle
        ));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createStub(Page::class);
        $resultPage->method('setActiveMenu')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsNewOfferPageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(0);

        $offer = $this->createStub(FreeGiftOffer::class);
        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerFactory->method('create')->willReturn($offer);

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_free_gift_offer', $offer);

        $resultPage = $this->makeResultPage('New Free Gift Offer');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $offerFactory, $offerResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingOffer(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(5);
        $offer->method('getName')->willReturn('Spend more, get more');

        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerFactory->method('create')->willReturn($offer);

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::once())->method('load')->with($offer, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit Free Gift Offer "Spend more, get more"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $offerFactory, $offerResource);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testExecuteRedirectsWhenOfferNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(99);

        $offer = $this->createStub(FreeGiftOffer::class);
        $offer->method('getEntityId')->willReturn(null);

        $offerFactory = $this->createStub(FreeGiftOfferFactory::class);
        $offerFactory->method('create')->willReturn($offer);

        $offerResource = $this->createStub(FreeGiftOfferResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $offerFactory, $offerResource);
        self::assertSame($redirect, $controller->execute());
    }
}
