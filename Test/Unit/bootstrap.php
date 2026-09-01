<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for a standalone `composer install` of just this module (CI, or anyone
 * without a full Magento install handy) — no live Magento application, but Test/Unit still
 * `createMock()`s plenty of Magento's *Factory classes, which have no physical source file:
 * Magento generates them on the fly the first time something references them, normally as part
 * of a full app bootstrap. This wires up the exact same standalone generator Magento's own
 * dev/tests/unit/framework/autoload.php uses, straight from magento/framework's own
 * TestFramework\Unit\Autoloader classes — nothing custom, no dependency this module doesn't
 * already have via require-dev.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

$generatedCodeDir = sys_get_temp_dir() . '/ordo-automation-test-generated-' . getmypid();

$generatorIo = new Io(
    new File(),
    $generatedCodeDir . '/' . DirectoryList::getDefaultConfig()[DirectoryList::GENERATED_CODE][DirectoryList::PATH]
);
$generatedCodeAutoloader = new GeneratedClassesAutoloader(
    [
        new ExtensionAttributesGenerator(),
        new ExtensionAttributesInterfaceGenerator(),
        new FactoryGenerator(),
    ],
    $generatorIo
);
spl_autoload_register([$generatedCodeAutoloader, 'load']);

Phrase::setRenderer(new Placeholder());
