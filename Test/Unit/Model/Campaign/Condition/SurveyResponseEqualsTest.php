<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Campaign\Condition\SurveyResponseEquals;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection as SurveyPromptCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\SurveyPrompt;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SurveyResponseEqualsTest extends TestCase
{
    private SurveyPromptCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $surveyPromptCollectionFactory;
    private SurveyResponseEquals $condition;

    protected function setUp(): void
    {
        $this->surveyPromptCollectionFactory = $this->createMock(SurveyPromptCollectionFactory::class);
        $this->condition = new SurveyResponseEquals(
            $this->surveyPromptCollectionFactory,
            $this->createStub(ResourceConnection::class)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenCustomerIdIsMissing(): void
    {
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->condition->isSatisfied([], ['question' => 'Favorite color?', 'response_text' => 'Blue']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenCustomerIdIsZero(): void
    {
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 0],
            ['question' => 'Favorite color?', 'response_text' => 'Blue']
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenQuestionParamIsMissing(): void
    {
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['response_text' => 'Blue']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenResponseTextParamIsMissing(): void
    {
        $this->surveyPromptCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['question' => 'Favorite color?']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsTrueWhenLatestResponseForThatQuestionMatches(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getResponseText')->willReturn('Blue');

        $collection = $this->createMock(SurveyPromptCollection::class);
        $collection->expects(self::once())->method('addLatestResponseFilterForQuestion')
            ->with(42, 'Favorite color?');
        $collection->method('getFirstItem')->willReturn($prompt);
        $this->surveyPromptCollectionFactory->method('create')->willReturn($collection);

        self::assertTrue($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['question' => 'Favorite color?', 'response_text' => 'Blue']
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenLatestResponseDoesNotMatch(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getResponseText')->willReturn('Red');

        $collection = $this->createMock(SurveyPromptCollection::class);
        $collection->method('getFirstItem')->willReturn($prompt);
        $this->surveyPromptCollectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['question' => 'Favorite color?', 'response_text' => 'Blue']
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenCustomerNeverAnsweredThatQuestion(): void
    {
        $prompt = $this->createMock(SurveyPrompt::class);
        $prompt->method('getResponseText')->willReturn(null);

        $collection = $this->createMock(SurveyPromptCollection::class);
        $collection->method('getFirstItem')->willReturn($prompt);
        $this->surveyPromptCollectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['question' => 'Favorite color?', 'response_text' => 'Blue']
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsFailsClosedWhenQuestionOrResponseTextMissing(): void
    {
        self::assertSame([], $this->condition->resolveCustomerIds(['response_text' => 'Blue']));
        self::assertSame([], $this->condition->resolveCustomerIds(['question' => 'Favorite color?']));
        self::assertSame([], $this->condition->resolveCustomerIds([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsReturnsIntIdsFromTheLatestResponseJoin(): void
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn(['3', '5']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $condition = new SurveyResponseEquals($this->surveyPromptCollectionFactory, $resourceConnection);

        self::assertSame(
            [3, 5],
            $condition->resolveCustomerIds(['question' => 'Favorite color?', 'response_text' => 'Blue'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetParamFieldsListsQuestionAndResponseText(): void
    {
        self::assertSame(['question', 'response_text'], array_column($this->condition->getParamFields(), 'name'));
    }
}
