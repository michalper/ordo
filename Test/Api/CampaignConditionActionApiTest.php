<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Full CRUD on a campaign's conditions/actions — the last gap flagged in API.md ("headless
 * client can create/enable/disable/delete campaigns, but not author their condition/action
 * rules") when this suite was first written. Closes it: everything the admin dynamicRows form
 * can do is now reachable over REST too.
 */
class CampaignConditionActionApiTest extends AbstractApiTestCase
{
    public function testConditionAndActionCrudRoundTrip(): void
    {
        [$status, $campaign] = $this->asAdmin('POST', '/rest/V1/ordo/campaigns', [
            'campaign' => ['name' => 'API Condition Test Campaign', 'trigger_event' => 'order_placed', 'enabled' => true],
        ]);
        self::assertSame(200, $status);
        $campaignId = $campaign['entity_id'];

        [$status, $condition] = $this->asAdmin('POST', '/rest/V1/ordo/campaign-conditions', [
            'condition' => [
                'campaign_id' => $campaignId,
                'type' => 'order_total_gte',
                'params_json' => '{"amount":"500"}',
                'sort_order' => 0,
            ],
        ]);
        self::assertSame(200, $status, json_encode($condition));
        self::assertSame('{"amount":"500"}', $condition['params_json']);
        $conditionId = $condition['entity_id'];

        [$status, $action] = $this->asAdmin('POST', '/rest/V1/ordo/campaign-actions', [
            'action' => [
                'campaign_id' => $campaignId,
                'type' => 'add_tag',
                'params_json' => '{"tag":"big_spender"}',
                'sort_order' => 0,
            ],
        ]);
        self::assertSame(200, $status, json_encode($action));
        $actionId = $action['entity_id'];

        // The list endpoints are flat resources filtered by campaign_id via searchCriteria —
        // this is what a headless client uses to fetch "every condition/action on campaign X".
        [$status, $list] = $this->asAdmin(
            'GET',
            '/rest/V1/ordo/campaign-conditions?searchCriteria[filterGroups][0][filters][0][field]=campaign_id'
            . "&searchCriteria[filterGroups][0][filters][0][value]={$campaignId}"
        );
        self::assertSame(200, $status);
        self::assertCount(1, $list['items']);
        self::assertSame($conditionId, $list['items'][0]['entity_id']);

        [$status, $updated] = $this->asAdmin('PUT', "/rest/V1/ordo/campaign-conditions/{$conditionId}", [
            'condition' => [
                'entity_id' => $conditionId,
                'campaign_id' => $campaignId,
                'type' => 'order_total_gte',
                'params_json' => '{"amount":"750"}',
                'sort_order' => 0,
            ],
        ]);
        self::assertSame(200, $status);
        self::assertSame('{"amount":"750"}', $updated['params_json']);

        [$status] = $this->asAdmin('DELETE', "/rest/V1/ordo/campaign-actions/{$actionId}");
        self::assertSame(200, $status);

        [$status, $notFound] = $this->asAdmin('GET', "/rest/V1/ordo/campaign-actions/{$actionId}");
        self::assertSame(404, $status);
        self::assertStringContainsString('does not exist', $notFound['message']);

        $this->asAdmin('DELETE', "/rest/V1/ordo/campaign-conditions/{$conditionId}");
        $this->asAdmin('DELETE', "/rest/V1/ordo/campaigns/{$campaignId}");
    }
}
