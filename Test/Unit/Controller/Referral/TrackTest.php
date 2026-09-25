<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Referral;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Ordo\Automation\Controller\Referral\Track;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class TrackTest extends AbstractFrontendActionTestCase
{
    private RedirectFactory $redirectFactory;
    private Redirect $redirect;
    private CustomerSession $customerSession;
    private ReferralManager $referralManager;
    private Config $config;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->redirectFactory = $this->createStub(RedirectFactory::class);
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->referralManager = $this->createMock(ReferralManager::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isReferralEnabled')->willReturn(true);
    }

    private function makeController(): Track
    {
        return new Track(
            $this->makeContext(),
            $this->redirectFactory,
            $this->customerSession,
            $this->referralManager,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteStashesCodeOnSessionWhenValid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturn('ABC12345');
        $this->referralManager->method('resolveReferrerCustomerId')->willReturn(7);

        // Magento\Customer\Model\Session has no declared setData() of its own - it resolves via
        // SessionManager::__call() to the underlying Storage object, so that's the real method
        // PHPUnit can actually stub/verify here, not setData() directly.
        $this->customerSession->expects(self::once())->method('__call')
            ->with('setData', [Track::SESSION_KEY, 'ABC12345']);

        $this->redirect->expects(self::once())->method('setPath')->with('customer/account/create');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteIgnoresUnknownCode(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturn('BOGUS');
        $this->referralManager->method('resolveReferrerCustomerId')->willReturn(null);

        $this->customerSession->expects(self::never())->method('__call');

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsResolutionWhenDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isReferralEnabled')->willReturn(false);

        $this->referralManager->expects(self::never())->method('resolveReferrerCustomerId');

        $this->makeController()->execute();
    }
}
