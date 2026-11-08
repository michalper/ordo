<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Controller\Track\Poll;
use Ordo\Automation\Model\Track\TrackerPollReader;
use Ordo\Automation\Model\Track\VisitorIdentityResolver;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * This controller's only own job is resolving identity the REST/session way, delegating to
 * TrackerPollReader, and wrapping the result as JSON - see
 * Test\Unit\Model\Track\TrackerPollReaderTest for the actual popup/notification/survey
 * business-logic coverage this used to duplicate.
 */
class PollTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private TrackerPollReader $trackerPollReader;
    private CustomerSession $customerSession;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->trackerPollReader = $this->createMock(TrackerPollReader::class);
        $this->customerSession = $this->createStub(CustomerSession::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Poll
    {
        return new Poll(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->trackerPollReader,
            new VisitorIdentityResolver($this->customerSession, $this->createStub(CookieManagerInterface::class))
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDelegatesToTheReaderWithTheVisitorIdFromTheRequestParam(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->trackerPollReader->expects(self::once())->method('resolve')->with(null, 'v1')
            ->willReturn(['popup' => null]);
        $this->jsonResult->expects(self::once())->method('setData')->with(['popup' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDelegatesWithTheLoggedInCustomerIdAndAnEmptyVisitorIdWhenMissing(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->willReturnMap([['visitor_id', '']]);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        $this->trackerPollReader->expects(self::once())->method('resolve')->with(42, '')
            ->willReturn(['notifications' => []]);
        $this->jsonResult->expects(self::once())->method('setData')->with(['notifications' => []]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateCsrfValidationExceptionReturnsNull(): void
    {
        $controller = $this->makeController();
        self::assertNull($controller->createCsrfValidationException($this->request));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidateForCsrfReturnsTrue(): void
    {
        $controller = $this->makeController();
        self::assertTrue($controller->validateForCsrf($this->request));
    }
}
