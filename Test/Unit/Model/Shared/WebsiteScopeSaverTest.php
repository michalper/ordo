<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Shared;

use Ordo\Automation\Model\CampaignWebsite;
use Ordo\Automation\Model\ResourceModel\Campaign\Website as CampaignWebsiteResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Website\Collection as WebsiteCollection;
use Ordo\Automation\Model\Shared\WebsiteScopeSaver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class WebsiteScopeSaverTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testSaveDeletesEveryExistingRowThenInsertsOneRowPerDistinctPositiveId(): void
    {
        $existingOne = $this->createStub(CampaignWebsite::class);
        $existingTwo = $this->createStub(CampaignWebsite::class);
        $existingWebsites = $this->createStub(WebsiteCollection::class);
        $existingWebsites->method('getIterator')->willReturn(new \ArrayIterator([$existingOne, $existingTwo]));

        $resource = $this->createMock(CampaignWebsiteResource::class);
        $resource->expects(self::exactly(2))->method('delete')
            ->with(self::callback(static fn (CampaignWebsite $w): bool => $w === $existingOne || $w === $existingTwo));
        $resource->expects(self::exactly(2))->method('save');

        $savedRows = [];
        $createWebsite = function () use (&$savedRows): CampaignWebsite {
            $website = $this->createMock(CampaignWebsite::class);
            $website->method('setData')->willReturnCallback(
                function (array $data) use ($website, &$savedRows): CampaignWebsite {
                    $savedRows[] = $data;
                    return $website;
                }
            );

            return $website;
        };

        $saver = new WebsiteScopeSaver();
        $saver->save(
            $existingWebsites,
            $resource,
            $createWebsite,
            'campaign_id',
            5,
            [3, '3', 0, -1, 'not-a-number', 4]
        );

        self::assertSame(
            [['campaign_id' => 5, 'website_id' => 3], ['campaign_id' => 5, 'website_id' => 4]],
            $savedRows
        );
    }

    public function testSaveDeletesExistingRowsWithoutInsertingAnythingForAnEmptySelection(): void
    {
        $existing = $this->createStub(CampaignWebsite::class);
        $existingWebsites = $this->createStub(WebsiteCollection::class);
        $existingWebsites->method('getIterator')->willReturn(new \ArrayIterator([$existing]));

        $resource = $this->createMock(CampaignWebsiteResource::class);
        $resource->expects(self::once())->method('delete')->with($existing);
        $resource->expects(self::never())->method('save');

        $saver = new WebsiteScopeSaver();
        $saver->save(
            $existingWebsites,
            $resource,
            fn (): CampaignWebsite => $this->createStub(CampaignWebsite::class),
            'campaign_id',
            5,
            []
        );
    }
}
