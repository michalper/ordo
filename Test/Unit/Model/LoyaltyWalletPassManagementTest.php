<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\LoyaltyWalletPassManagement;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LoyaltyWalletPassManagementTest extends TestCase
{
    private GoogleWalletPassLinkBuilder $googleWalletPassLinkBuilder;
    private UserContextInterface $userContext;

    protected function setUp(): void
    {
        $this->googleWalletPassLinkBuilder = $this->createMock(GoogleWalletPassLinkBuilder::class);
        $this->userContext = $this->createStub(UserContextInterface::class);
    }

    private function makeManagement(): LoyaltyWalletPassManagement
    {
        return new LoyaltyWalletPassManagement($this->googleWalletPassLinkBuilder, $this->userContext);
    }

    public function testGetMyGoogleWalletSaveUrlResolvesTheCurrentCustomerFromUserContext(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->googleWalletPassLinkBuilder->expects(self::once())->method('buildSaveUrl')->with(42)
            ->willReturn('https://pay.google.com/gp/v/save/jwt123');

        self::assertSame(
            'https://pay.google.com/gp/v/save/jwt123',
            $this->makeManagement()->getMyGoogleWalletSaveUrl()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyGoogleWalletSaveUrlThrowsWhenUnauthenticated(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->getMyGoogleWalletSaveUrl();
    }

    /**
     * Same guest-GraphQL trap as LoyaltyPointsManagementTest's own regression test -
     * UserContextInterface returns 0 for a guest, not null.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyGoogleWalletSaveUrlThrowsWhenUserContextReturnsZero(): void
    {
        $this->userContext->method('getUserId')->willReturn(0);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->getMyGoogleWalletSaveUrl();
    }
}
