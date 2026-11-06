<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;
use Magento\FunctionalTestingFramework\Module\MagentoWebDriver;

/**
 * Wraps "queue:consumers:start" with a retry. The <magentoCLI> bridge (command.php, a fresh
 * `php -f bin/magento ...` child process per call — see AGENTS.md) has been observed in CI
 * returning empty output in well under a second on loaded/scheduled runners: too fast for
 * Magento's own bootstrap, let alone to have drained anything, leaving the message still queued
 * and every assertion depending on it failing (every MFTF shard, intermittently, since around
 * 2026-10-29 — see the scheduled-run history). A second identical call is safe either way: with
 * --consumers-wait-for-messages=0 set repo-wide, a call against an already-empty queue just
 * returns immediately, so retrying costs almost nothing on the common case where the first call
 * already worked.
 */
class QueueConsumerHelper extends Helper
{
    public function drainQueueWithRetry(
        string $topic,
        int $maxMessages = 50,
        int $attempts = 3,
        int $backoffSeconds = 1
    ): void {
        /** @var MagentoWebDriver $driver */
        $driver = $this->getModule('\Magento\FunctionalTestingFramework\Module\MagentoWebDriver');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $driver->magentoCLI("queue:consumers:start {$topic} --max-messages={$maxMessages}", 60);

            if ($attempt < $attempts) {
                sleep($backoffSeconds * $attempt);
            }
        }
    }
}
