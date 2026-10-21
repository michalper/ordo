<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Shared\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\BackButton;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\DeleteButton;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\SaveAndContinueButton;
use Ordo\Automation\Block\Adminhtml\Shared\Edit\SaveButton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The per-entity edit-form buttons are virtualTypes of the shared parametrized button classes
 * (see etc/adminhtml/di.xml) — Magento resolves a ui_component <button class="..."> through
 * ButtonProviderFactory -> ObjectManager::create(), which accepts virtualType names. Nothing
 * executes that XML in a unit run, so this test is the guard that the wiring holds together:
 * every button class referenced by a form XML must be a real ButtonProviderInterface or a
 * declared virtualType of one, and each entity's virtualType must carry the strings the old
 * per-entity classes hardcoded.
 */
class ButtonWiringTest extends TestCase
{
    private const ENTITY_FORMS = [
        'ordo_adaudience_form' => [
            'entity' => 'AdAudience',
            'saveLabel' => 'Save Ad Audience',
            'confirmMessage' => 'Are you sure you want to delete this ad audience?',
        ],
        'ordo_campaign_form' => [
            'entity' => 'Campaign',
            'saveLabel' => 'Save Campaign',
            'confirmMessage' => 'Are you sure you want to delete this campaign?',
        ],
        'ordo_contentblock_form' => [
            'entity' => 'ContentBlock',
            'saveLabel' => 'Save Content Block',
            'confirmMessage' => 'Are you sure you want to delete this content block?',
        ],
        'ordo_free_gift_offer_form' => [
            'entity' => 'FreeGiftOffer',
            'saveLabel' => 'Save Offer',
            'confirmMessage' => 'Are you sure you want to delete this free gift offer?',
        ],
        'ordo_leadroutingrule_form' => [
            'entity' => 'LeadRoutingRule',
            'saveLabel' => 'Save Lead Routing Rule',
            'confirmMessage' => 'Are you sure you want to delete this lead routing rule?',
        ],
        'ordo_scorerule_form' => [
            'entity' => 'ScoreRule',
            'saveLabel' => 'Save Score Rule',
            'confirmMessage' => 'Are you sure you want to delete this score rule?',
        ],
        'ordo_segment_form' => [
            'entity' => 'Segment',
            'saveLabel' => 'Save Segment',
            'confirmMessage' => 'Are you sure you want to delete this segment?',
        ],
        'ordo_whatsapptemplate_form' => [
            'entity' => 'WhatsAppTemplate',
            'saveLabel' => 'Save Template',
            'confirmMessage' => 'Are you sure you want to delete this WhatsApp template?',
        ],
    ];

    public function testEveryFormButtonClassResolvesToAButtonProvider(): void
    {
        $virtualTypes = $this->loadVirtualTypes();
        $buttonsSeen = 0;

        foreach (glob($this->moduleRoot() . '/view/adminhtml/ui_component/*.xml') ?: [] as $file) {
            foreach ($this->loadFormButtons($file) as $name => $class) {
                $buttonsSeen++;
                if (isset($virtualTypes[$class])) {
                    $class = $virtualTypes[$class]['type'];
                }
                self::assertTrue(
                    class_exists($class),
                    sprintf('Button "%s" in %s references unknown class/virtualType "%s"', $name, basename($file), $class)
                );
                self::assertTrue(
                    is_subclass_of($class, ButtonProviderInterface::class),
                    sprintf('Button "%s" in %s must implement ButtonProviderInterface', $name, basename($file))
                );
            }
        }

        // 8 entity forms x 4 shared buttons + RefreshRss, RefreshStatus and SubmitForReview.
        self::assertSame(35, $buttonsSeen);
    }

    /**
     * @return iterable<string, array{string, array{entity: string, saveLabel: string, confirmMessage: string}}>
     */
    public static function entityFormProvider(): iterable
    {
        foreach (self::ENTITY_FORMS as $form => $expected) {
            yield $form => [$form, $expected];
        }
    }

    /**
     * @param array{entity: string, saveLabel: string, confirmMessage: string} $expected
     */
    #[DataProvider('entityFormProvider')]
    public function testEntityFormWiresTheSharedButtonsWithItsOwnStrings(string $form, array $expected): void
    {
        $buttons = $this->loadFormButtons($this->moduleRoot() . '/view/adminhtml/ui_component/' . $form . '.xml');
        $virtualTypes = $this->loadVirtualTypes();
        $entity = $expected['entity'];
        $targetName = $form . '.' . $form;

        self::assertSame(BackButton::class, $buttons['back']);

        self::assertSame('OrdoAutomation' . $entity . 'SaveButton', $buttons['save']);
        $save = $virtualTypes[$buttons['save']];
        self::assertSame(SaveButton::class, $save['type']);
        self::assertSame(
            ['label' => $expected['saveLabel'], 'targetName' => $targetName],
            $save['arguments']
        );

        self::assertSame('OrdoAutomation' . $entity . 'SaveAndContinueButton', $buttons['save_and_continue']);
        $saveAndContinue = $virtualTypes[$buttons['save_and_continue']];
        self::assertSame(SaveAndContinueButton::class, $saveAndContinue['type']);
        self::assertSame(['targetName' => $targetName], $saveAndContinue['arguments']);

        self::assertSame('OrdoAutomation' . $entity . 'DeleteButton', $buttons['delete']);
        $delete = $virtualTypes[$buttons['delete']];
        self::assertSame(DeleteButton::class, $delete['type']);
        self::assertSame(['confirmMessage' => $expected['confirmMessage']], $delete['arguments']);
    }

    /**
     * @param array{entity: string, saveLabel: string, confirmMessage: string} $expected
     */
    #[DataProvider('entityFormProvider')]
    public function testConfiguredArgumentsFlowIntoTheButtonData(string $form, array $expected): void
    {
        $context = $this->createStub(Context::class);
        $targetName = $form . '.' . $form;

        $saveData = (new SaveButton($context, $expected['saveLabel'], $targetName))->getButtonData();
        self::assertSame($expected['saveLabel'], (string) $saveData['label']);
        self::assertSame(
            $targetName,
            $saveData['data_attribute']['mage-init']['buttonAdapter']['actions'][0]['targetName']
        );

        $continueData = (new SaveAndContinueButton($context, $targetName))->getButtonData();
        self::assertSame(
            $targetName,
            $continueData['data_attribute']['mage-init']['buttonAdapter']['actions'][0]['targetName']
        );
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 6);
    }

    /**
     * @return array<string, string> button name => class attribute
     */
    private function loadFormButtons(string $file): array
    {
        $xml = simplexml_load_file($file);
        self::assertNotFalse($xml, 'Unparseable ui_component XML: ' . $file);

        $buttons = [];
        foreach ($xml->xpath('//settings/buttons/button') ?: [] as $button) {
            // Buttons declared purely in XML (label/url children, e.g. a listing's "Add New")
            // have no PHP provider class and are out of scope here.
            if ((string) $button['class'] !== '') {
                $buttons[(string) $button['name']] = (string) $button['class'];
            }
        }

        return $buttons;
    }

    /**
     * @return array<string, array{type: string, arguments: array<string, string>}>
     */
    private function loadVirtualTypes(): array
    {
        $xml = simplexml_load_file($this->moduleRoot() . '/etc/adminhtml/di.xml');
        self::assertNotFalse($xml, 'Unparseable etc/adminhtml/di.xml');

        $virtualTypes = [];
        foreach ($xml->xpath('/config/virtualType') ?: [] as $virtualType) {
            $arguments = [];
            foreach ($virtualType->xpath('arguments/argument') ?: [] as $argument) {
                $arguments[(string) $argument['name']] = (string) $argument;
            }
            $virtualTypes[(string) $virtualType['name']] = [
                'type' => (string) $virtualType['type'],
                'arguments' => $arguments,
            ];
        }

        return $virtualTypes;
    }
}
