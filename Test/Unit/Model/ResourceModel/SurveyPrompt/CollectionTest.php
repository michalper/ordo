<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\SurveyPrompt;

use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddTargetFilterIsFluentWithBothIdentifiers(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(42, 'v1', '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testAddTargetFilterIsFluentWithCustomerIdOnly(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(42, null, '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testAddTargetFilterIsFluentWithVisitorIdOnly(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(null, 'v1', '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testAddLatestResponseFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addLatestResponseFilter(42);

        self::assertSame($collection, $result);
    }

    public function testAddLatestResponseFilterForQuestionIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addLatestResponseFilterForQuestion(42, 'Favorite color?');

        self::assertSame($collection, $result);
    }
}
