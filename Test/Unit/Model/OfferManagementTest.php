<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\OfferManagement;
use Ordo\Automation\Api\OfferRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class OfferManagementTest extends TestCase
{
    private OfferRepositoryInterface $offerRepository;
    private Config $config;
    private UserContextInterface $userContext;
    private OfferManagement $management;

    protected function setUp(): void
    {
        $this->offerRepository = $this->createMock(OfferRepositoryInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->userContext = $this->createStub(UserContextInterface::class);

        $this->management = new OfferManagement($this->offerRepository, $this->config, $this->userContext);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelfExtendThrowsWhenOfferBelongsToDifferentCustomer(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getCustomerId')->willReturn(5);
        $this->offerRepository->method('getById')->willReturnMap([[10, $offer]]);

        $this->userContext->method('getUserId')->willReturn(9);

        $this->expectException(NoSuchEntityException::class);
        $this->management->selfExtend(10);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelfExtendThrowsWhenUserContextHasNoCustomerId(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getCustomerId')->willReturn(5);
        $this->offerRepository->method('getById')->willReturn($offer);

        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);
        $this->management->selfExtend(10);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelfExtendThrowsWhenAlreadyAtMaxExtensions(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getCustomerId')->willReturn(5);
        $offer->method('canSelfExtend')->willReturnMap([[1, false]]);
        $this->offerRepository->method('getById')->willReturn($offer);

        $this->userContext->method('getUserId')->willReturn(5);
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(1);

        $this->expectException(LocalizedException::class);
        $this->management->selfExtend(10);
    }

    public function testSelfExtendPushesExpiryAndIncrementsCount(): void
    {
        $offer = $this->createMock(Offer::class);
        $offer->method('getCustomerId')->willReturn(5);
        $offer->method('canSelfExtend')->willReturnMap([[2, true]]);
        $offer->method('getExpiresAt')->willReturn('2026-01-01 00:00:00');
        $offer->method('getExtensionCount')->willReturn(0);
        $offer->expects(self::once())->method('setExpiresAt')->with('2026-01-08 00:00:00');
        $offer->expects(self::once())->method('setExtensionCount')->with(1);
        $this->offerRepository->method('getById')->willReturnMap([[10, $offer]]);
        $this->offerRepository->expects(self::once())->method('save')->with($offer)->willReturn($offer);

        $this->userContext->method('getUserId')->willReturn(5);
        $this->config->method('getOfferMaxSelfExtensions')->willReturn(2);
        $this->config->method('getOfferSelfExtensionDays')->willReturn(7);

        self::assertSame($offer, $this->management->selfExtend(10));
    }
}
