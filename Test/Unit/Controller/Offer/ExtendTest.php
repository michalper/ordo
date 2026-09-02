<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Offer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\OfferInterface;
use Ordo\Automation\Controller\Offer\Extend;
use Ordo\Automation\Model\OfferManagement;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ExtendTest extends AbstractFrontendActionTestCase
{
    private CustomerSession $customerSession;
    private CustomerUrl $customerUrl;
    private OfferManagement $offerManagement;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->customerUrl = $this->createStub(CustomerUrl::class);
        $this->offerManagement = $this->createMock(OfferManagement::class);
    }

    private function makeController(): Extend
    {
        return new Extend(
            $this->makeContext(),
            $this->customerSession,
            $this->customerUrl,
            $this->offerManagement
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorAndRedirectsWhenOfferIdInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('offer_id')->willReturn('0');

        $this->offerManagement->expects(self::never())->method('selfExtend');
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorWhenOfferNotFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('offer_id')->willReturn('5');
        $this->offerManagement->method('selfExtend')->with(5)
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with(__('This offer could not be found.'));

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorWhenExtensionNotAllowed(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('offer_id')->willReturn('5');
        $exception = new LocalizedException(__('This offer has already been extended the maximum of 2 time(s).'));
        $this->offerManagement->method('selfExtend')->with(5)->willThrowException($exception);

        $this->messageManager->expects(self::once())->method('addErrorMessage')->with($exception->getMessage());

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteExtendsOfferAndRedirectsWithSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('offer_id')->willReturn('5');

        $offer = $this->createStub(OfferInterface::class);
        $offer->method('getReference')->willReturn('OFR-5');
        $offer->method('getExpiresAt')->willReturn('2026-10-01 00:00:00');
        $this->offerManagement->method('selfExtend')->with(5)->willReturn($offer);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
