<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

/**
 * Every helper in this directory that needs to reach a table or service with "no MFTF-reachable
 * path" used to open its own `new \PDO('mysql:host=127.0.0.1;...', 'root', '')` — correct only by
 * coincidence in the two environments this module had actually been run in (the local
 * `magento-ordo-test/` sandbox: one container, DB on localhost; GitHub Actions: a MySQL service
 * container GitHub Actions itself maps onto localhost for the job). Adobe Commerce Marketplace's
 * own technical-review harness (`docker-compose-mftf.yml`) is a real multi-container Compose
 * stack with separate `build`/`deploy`/`test`/db services — "127.0.0.1" inside the `test`
 * container is that container itself, never the database, so every one of those PDO calls failed
 * there with "SQLSTATE[HY000] [2002] Connection refused" (confirmed via the review's own MFTF
 * Vendor Supplied test report).
 *
 * This resolves the real connection the already-deployed Magento instance under test is using,
 * straight from its own `app/etc/env.php` (correct in any environment by construction — it's the
 * same file Magento itself connects with), falling back to the historical 127.0.0.1/root/no-password
 * default only if that file can't be found or read, so behavior in the sandbox and in CI (where
 * the old default happens to be correct) is unchanged.
 */
trait DbConnectionTrait
{
    /**
     * @return array{host: string, dbname: string, username: string, password: string}
     */
    private function resolveDbCredentials(): array
    {
        $fallback = [
            'host' => '127.0.0.1',
            'dbname' => 'magento',
            'username' => 'root',
            'password' => '',
        ];

        $magentoRoot = $this->findMagentoRoot();
        if ($magentoRoot === null) {
            return $fallback;
        }

        try {
            $config = include $magentoRoot . '/app/etc/env.php';
            if (!\is_array($config)) {
                return $fallback;
            }

            $connection = $config['db']['connection']['default'] ?? null;
            if (!\is_array($connection) || !isset($connection['host'], $connection['dbname'], $connection['username'])) {
                return $fallback;
            }

            return [
                'host' => (string) $connection['host'],
                'dbname' => (string) $connection['dbname'],
                'username' => (string) $connection['username'],
                'password' => (string) ($connection['password'] ?? ''),
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function resolveDbConnection(): \PDO
    {
        $credentials = $this->resolveDbCredentials();

        return new \PDO(
            "mysql:host={$credentials['host']};dbname={$credentials['dbname']};charset=utf8mb4",
            $credentials['username'],
            $credentials['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * MailHogHelper's equivalent of resolveDbConnection(): the review harness points Magento's
     * own SMTP transport at the real Compose service name ("mailhog", not "127.0.0.1" — see its
     * own `magento-command config:set system/smtp/host mailhog`), and MailHog's HTTP API always
     * lives on the same host as its SMTP listener, just port 8025 instead of 1025. Reading
     * `system/smtp/host` back out of core_config_data therefore gives the right host in any
     * environment that actually configured SMTP that way, while falling back to the historical
     * 127.0.0.1 default for the sandbox/CI, which route mail through `sendmail`/mhsendmail
     * instead and never set this path at all.
     */
    private function resolveMailHogBaseUrl(): string
    {
        $fallback = 'http://127.0.0.1:8025';

        try {
            $pdo = $this->resolveDbConnection();
            $statement = $pdo->prepare(
                "SELECT value FROM core_config_data WHERE path = 'system/smtp/host' AND scope_id = 0 LIMIT 1"
            );
            $statement->execute();
            $host = $statement->fetchColumn();
        } catch (\Throwable) {
            return $fallback;
        }

        if (!\is_string($host) || $host === '') {
            return $fallback;
        }

        return "http://{$host}:8025";
    }

    /**
     * Walks up from this trait's own location looking for the Magento root (identified by
     * `app/etc/env.php`) rather than assuming a fixed `vendor/<vendor>/<package>` depth, so it
     * still works under any install layout (the local sandbox's path-repository copy, a regular
     * Composer install, etc).
     */
    private function findMagentoRoot(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_file($dir . '/app/etc/env.php')) {
                return $dir;
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }
}
