<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Controller\Track\Popup;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection as PendingPopupCollection;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class PopupTest extends AbstractFrontendActionTestCase
{
    private JsonFactory $resultJsonFactory;
    private PendingPopupCollectionFactory $pendingPopupCollectionFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private CustomerSession $customerSession;
    private Config $config;
    private Json $jsonResult;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createStub(JsonFactory::class);
        $this->pendingPopupCollectionFactory = $this->createMock(PendingPopupCollectionFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->config = $this->createStub(Config::class);

        $this->jsonResult = $this->createMock(Json::class);
        $this->jsonResult->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->jsonResult);
    }

    private function makeController(): Popup
    {
        return new Popup(
            $this->makeContext(),
            $this->resultJsonFactory,
            $this->pendingPopupCollectionFactory,
            $this->resourceConnection,
            $this->customerSession,
            $this->config
        );
    }

    private function makeCollection(array $popups): PendingPopupCollection
    {
        $collection = $this->createStub(PendingPopupCollection::class);
        $collection->method('addTargetFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($popups));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullPopupWhenDisabled(): void
    {
        $controller = $this->makeController();
        $this->config->method('isPopupEnabled')->willReturn(false);

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['popup' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullPopupWhenNoIdentifierGiven(): void
    {
        $controller = $this->makeController();
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', '']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->jsonResult->expects(self::once())->method('setData')->with(['popup' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsNullPopupWhenNoneQueued(): void
    {
        $controller = $this->makeController();
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makeCollection([]));

        $this->jsonResult->expects(self::once())->method('setData')->with(['popup' => null]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndReturnsPopupForAnonymousVisitor(): void
    {
        $controller = $this->makeController();
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $popup = $this->createStub(PendingPopup::class);
        $popup->method('getId')->willReturn(9);
        $popup->method('getHeadline')->willReturn('Hello!');
        $popup->method('getBody')->willReturn('Come back soon');
        $popup->method('getCtaLabel')->willReturn('Shop now');
        $popup->method('getCtaUrl')->willReturn('https://example.test/sale');

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makeCollection([$popup]));
        $this->connection->expects(self::once())->method('update')
            ->with('ordo_pending_popup', self::isArray(), self::callback(
                fn (array $where) => $where['entity_id = ?'] === 9
            ))
            ->willReturn(1);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'popup' => [
                'headline' => 'Hello!',
                'body' => 'Come back soon',
                'cta_label' => 'Shop now',
                'cta_url' => 'https://example.test/sale',
            ],
        ]);

        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteFallsThroughToNextCandidateWhenClaimLosesRace(): void
    {
        $controller = $this->makeController();
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([['visitor_id', 'v1']]);
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $lost = $this->createStub(PendingPopup::class);
        $lost->method('getId')->willReturn(9);

        $won = $this->createStub(PendingPopup::class);
        $won->method('getId')->willReturn(10);
        $won->method('getHeadline')->willReturn('Second in line');
        $won->method('getBody')->willReturn(null);
        $won->method('getCtaLabel')->willReturn(null);
        $won->method('getCtaUrl')->willReturn(null);

        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->makeCollection([$lost, $won]));
        $this->connection->method('update')->willReturnOnConsecutiveCalls(0, 1);

        $this->jsonResult->expects(self::once())->method('setData')->with([
            'popup' => [
                'headline' => 'Second in line',
                'body' => null,
                'cta_label' => null,
                'cta_url' => null,
            ],
        ]);

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
