<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\TemplateLibrary;
use PHPUnit\Framework\TestCase;

class TemplateLibraryTest extends TestCase
{
    public function testGetTemplatesReturnsExactlyTheThreeCuratedTemplates(): void
    {
        $ids = array_column((new TemplateLibrary())->getTemplates(), 'id');

        self::assertSame(['welcome_series', 'cart_abandonment_follow_up', 'vip_upsell'], $ids);
    }

    public function testGetTemplateReturnsNullForAnUnknownId(): void
    {
        self::assertNull((new TemplateLibrary())->getTemplate('nonexistent'));
    }

    public function testGetTemplateReturnsTheMatchingTemplate(): void
    {
        $template = (new TemplateLibrary())->getTemplate('vip_upsell');

        self::assertNotNull($template);
        self::assertSame('vip_upsell', $template['id']);
        self::assertSame('ordo_campaign', $template['payload']['export_type']);
    }

    public function testEveryTemplatePayloadIsWellFormedForCampaignImporter(): void
    {
        foreach ((new TemplateLibrary())->getTemplates() as $template) {
            $payload = $template['payload'];

            self::assertSame('ordo_campaign', $payload['export_type'], $template['id'] . ': export_type');
            self::assertNotSame('', trim((string) $payload['name']), $template['id'] . ': name must not be blank');
            self::assertFalse($payload['enabled'], $template['id'] . ': must import disabled');
            self::assertNotEmpty($payload['triggers'], $template['id'] . ': must have at least one trigger');
            self::assertNotEmpty($payload['actions'], $template['id'] . ': must have at least one action');

            foreach ($payload['triggers'] as $trigger) {
                self::assertArrayHasKey('trigger_event', $trigger, $template['id']);
                self::assertIsArray($trigger['params'], $template['id']);
            }

            foreach ($payload['actions'] as $action) {
                self::assertArrayHasKey('type', $action, $template['id']);
                self::assertIsArray($action['params'], $template['id']);
                self::assertIsInt($action['delay_minutes'], $template['id']);
            }
        }
    }
}
