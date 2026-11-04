<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\Save;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website as ScoreRuleWebsiteResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\Collection as ScoreRuleWebsiteCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRuleFactory;
use Ordo\Automation\Model\ScoreRuleWebsiteFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SaveTest extends AbstractAdminActionTestCase
{
    private ScoreRuleFactory $scoreRuleFactory;
    private ScoreRuleResource $scoreRuleResource;
    private ScoreRuleWebsiteFactory $scoreRuleWebsiteFactory;
    private ScoreRuleWebsiteResource $scoreRuleWebsiteResource;
    private ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory;

    protected function setUp(): void
    {
        $this->scoreRuleFactory = $this->createMock(ScoreRuleFactory::class);
        $this->scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $this->scoreRuleWebsiteFactory = $this->createMock(ScoreRuleWebsiteFactory::class);
        $this->scoreRuleWebsiteResource = $this->createMock(ScoreRuleWebsiteResource::class);
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')->willReturn($this->emptyWebsiteCollection());
    }

    private function emptyWebsiteCollection(): ScoreRuleWebsiteCollection
    {
        $collection = $this->createStub(ScoreRuleWebsiteCollection::class);
        $collection->method('addScoreRuleFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
            $this->scoreRuleFactory,
            $this->scoreRuleResource,
            $this->scoreRuleWebsiteFactory,
            $this->scoreRuleWebsiteResource,
            $this->scoreRuleWebsiteCollectionFactory
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->scoreRuleFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesNewScoreRuleAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'points' => '10',
            'enabled' => '1',
            'sort_order' => '0',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $scoreRule = $this->createMock(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(7);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $this->scoreRuleResource->expects(self::never())->method('load');
        $this->scoreRuleResource->expects(self::once())->method('save')->with($scoreRule);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingScoreRuleBeforeUpdating(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 3,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'points' => '-5',
            'enabled' => '0',
            'sort_order' => '10',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', '1']]);

        $scoreRule = $this->createMock(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(3);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $this->scoreRuleResource->expects(self::once())->method('load')->with($scoreRule, 3);
        $this->scoreRuleResource->expects(self::once())->method('save')->with($scoreRule);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'attribute_code' => 'group_id']);

        $scoreRule = $this->createStub(ScoreRule::class);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);
        $this->scoreRuleResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSavesPostedWebsiteIdsDeduplicatingAndSkippingInvalidValues(): void
    {
        $controller = $this->makeController();
        $postData = [
            'entity_id' => 0,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
            'website_ids' => [1, 2, '2', 0, -1],
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(7);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $savedWebsiteIds = [];
        $this->scoreRuleWebsiteFactory->method('create')->willReturnCallback(function () use (&$savedWebsiteIds) {
            $website = $this->createMock(\Ordo\Automation\Model\ScoreRuleWebsite::class);
            $website->method('setData')->willReturnCallback(function (array $data) use ($website, &$savedWebsiteIds) {
                self::assertSame(7, $data['score_rule_id']);
                $savedWebsiteIds[] = $data['website_id'];
                return $website;
            });
            return $website;
        });
        $this->scoreRuleWebsiteResource->expects(self::exactly(2))->method('save');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();

        self::assertSame([1, 2], $savedWebsiteIds);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesExistingWebsiteRowsWhenNoneArePosted(): void
    {
        // makeContext() (not makeController()) here - it's the one that actually initializes
        // $this->request, and calling it a second time (as makeController() would) replaces
        // $this->request with a brand new, unstubbed mock.
        $context = $this->makeContext();

        $postData = [
            'entity_id' => 3,
            'attribute_code' => 'group_id',
            'operator' => 'equals',
            'value' => '1',
        ];
        $this->request->method('getPostValue')->willReturn($postData);
        $this->request->method('getParam')->willReturnMap([['back', null]]);

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRule->method('getEntityId')->willReturn(3);
        $this->scoreRuleFactory->method('create')->willReturn($scoreRule);

        $existingWebsite = $this->createMock(\Ordo\Automation\Model\ScoreRuleWebsite::class);
        // A fresh stub, not a second ->method('create') on the one from setUp() - PHPUnit
        // matches configured behaviors in configuration order for an unlimited (any()) matcher,
        // so a second willReturn() on the same double never overrides the first.
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $collection = $this->createStub(ScoreRuleWebsiteCollection::class);
        $collection->method('addScoreRuleFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$existingWebsite]));
        $this->scoreRuleWebsiteCollectionFactory->method('create')->willReturn($collection);

        $this->scoreRuleWebsiteResource->expects(self::once())->method('delete')->with($existingWebsite);
        $this->scoreRuleWebsiteFactory->expects(self::never())->method('create');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        // Constructed directly with $context (not makeController(), which would call
        // makeContext() again) - captures scoreRuleWebsiteCollectionFactory's reassigned value
        // above at construction time.
        $controller = new Save(
            $context,
            $this->scoreRuleFactory,
            $this->scoreRuleResource,
            $this->scoreRuleWebsiteFactory,
            $this->scoreRuleWebsiteResource,
            $this->scoreRuleWebsiteCollectionFactory
        );

        $controller->execute();
    }
}
