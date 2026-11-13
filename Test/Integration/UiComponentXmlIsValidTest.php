<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Config\DataInterfaceFactory;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for a real, production-blocking bug: every admin page built from one of this
 * module's view/adminhtml/ui_component/*.xml files (campaign/segment editors, every grid) 500'd
 * because ordo_campaign_form.xml/ordo_segment_form.xml had a `<rule>` landing outside its
 * `<rules>` container - well-formed XML (a plain xmllint/DOM parse never catches it), but invalid
 * against Magento's own ui_configuration.xsd, which only Magento\Ui\Config\Reader itself checks
 * (see Magento\Framework\View\Layout\Reader\UiComponent -> Magento\Ui\Config\Data::initData() ->
 * Magento\Ui\Config\Reader::read(), the exact path a real controller dispatch takes). Neither
 * PHPCS/PHPStan/Rector nor Test/Unit ever construct a real UI component, so this shipped straight
 * through ci.yml and sat on main for almost a day before MFTF's own nightly run (not wired to
 * every PR - see mftf.yml) caught it. This test reuses the exact same production entry point
 * (the "uiComponentConfigFactory" virtual type - see vendor/magento/module-ui/etc/di.xml, shared
 * by both Magento\Framework\View\Layout\Reader\UiComponent and
 * Magento\Framework\View\Element\UiComponentFactory) for every form/listing XML this module
 * ships, so a future merge corrupting one the same way fails loudly here instead of silently
 * shipping a broken admin page again.
 */
class UiComponentXmlIsValidTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    public static function setUpBeforeClass(): void
    {
        require_once BP . '/app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        self::$objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    }

    /**
     * sales_rule_form.xml merely EXTENDS Magento_SalesRule's own Cart Price Rule edit form with a
     * couple of extra fields - it's not a page this module serves on its own. Reading it through
     * initData() calls evaluateComponentArguments(), which eagerly instantiates every merged
     * "xsi:type=object" argument, including Magento\SalesRule\Model\Rule\DataProvider - and that
     * constructor needs a real rule_id request param this test has no reason to fabricate.
     * Confirmed via a real CI run: it fails with a TypeError inside core's own DataProvider, not
     * anything this module's XML controls. Every file this module actually owns end-to-end stays
     * covered.
     *
     * @var string[]
     */
    private const array OUT_OF_SCOPE_FILES = ['sales_rule_form'];

    /**
     * @return string[][]
     */
    public static function uiComponentFileProvider(): array
    {
        $moduleDir = dirname(__DIR__, 2);
        $files = glob($moduleDir . '/view/adminhtml/ui_component/*.xml');
        self::assertNotEmpty($files, 'expected at least one ui_component XML file to check');

        $cases = [];
        foreach ($files as $file) {
            $componentName = basename($file, '.xml');
            if (in_array($componentName, self::OUT_OF_SCOPE_FILES, true)) {
                continue;
            }
            $cases[$componentName] = [$componentName];
        }

        return $cases;
    }

    #[DataProvider('uiComponentFileProvider')]
    public function testUiComponentXmlPassesMagentosOwnSchemaValidation(string $componentName): void
    {
        /** @var DataInterfaceFactory $configFactory */
        $configFactory = self::$objectManager->get('uiComponentConfigFactory');
        $config = $configFactory->create(['componentName' => $componentName]);

        try {
            $data = $config->get($componentName);
        } catch (\Throwable $e) {
            self::fail(sprintf(
                '%s.xml failed Magento\'s own ui_component XSD validation: %s',
                $componentName,
                $e->getMessage()
            ));
        }

        self::assertNotEmpty($data, "{$componentName}.xml resolved to no configuration at all - check the filename matches a real <name> attribute");
    }
}
