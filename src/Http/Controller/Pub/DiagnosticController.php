<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Environment self-check at /_diag. Confirms the pieces that are easy to get
 * wrong on this machine: which PHP is running, whether the extensions loaded,
 * and whether the connection overrides that protect against the XAMPP my.ini
 * defaults actually took effect.
 */
final class DiagnosticController
{
    public function index(Request $request): Response
    {
        $checks = [];

        $checks[] = self::row('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>='));
        $checks[] = self::row('php.ini', php_ini_loaded_file() ?: '(none)', php_ini_loaded_file() !== false);
        $checks[] = self::row('date.timezone', date_default_timezone_get(), date_default_timezone_get() === 'Asia/Tokyo');
        $checks[] = self::row('default_charset', (string) ini_get('default_charset'), strtoupper((string) ini_get('default_charset')) === 'UTF-8');
        $checks[] = self::row('mb_internal_encoding', mb_internal_encoding(), mb_internal_encoding() === 'UTF-8');

        foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo'] as $extension) {
            $checks[] = self::row("extension: {$extension}", extension_loaded($extension) ? 'loaded' : 'MISSING', extension_loaded($extension));
        }

        foreach ([
            'storage/logs'     => APP_ROOT . '/storage/logs',
            'storage/mail'     => APP_ROOT . '/storage/mail',
            'storage/sessions' => APP_ROOT . '/storage/sessions',
        ] as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $checks[] = self::row("writable: {$label}", $writable ? 'ok' : 'NOT WRITABLE', $writable);
        }

        try {
            $server = Db::selectOne(
                'SELECT VERSION() AS version,
                        @@session.sql_mode AS sql_mode,
                        @@session.time_zone AS time_zone,
                        @@session.innodb_lock_wait_timeout AS lock_timeout,
                        @@character_set_client AS cs_client,
                        @@character_set_connection AS cs_connection'
            ) ?? [];

            // Asked separately because the variable was renamed: MySQL 8
            // removed tx_isolation outright, while MariaDB only learned the
            // new name (transaction_isolation) in 11.1.
            try {
                $isolation = (string) Db::scalar('SELECT @@session.transaction_isolation');
            } catch (Throwable) {
                $isolation = (string) Db::scalar('SELECT @@session.tx_isolation');
            }

            $checks[] = self::row('DB connection', 'ok', true);
            $checks[] = self::row('database version', (string) ($server['version'] ?? ''), true);

            $sqlMode = (string) ($server['sql_mode'] ?? '');
            $checks[] = self::row(
                'session sql_mode',
                $sqlMode,
                str_contains($sqlMode, 'STRICT_TRANS_TABLES'),
                'my.ini omits STRICT_TRANS_TABLES; Db.php must add it or over-long values are silently truncated.'
            );

            $checks[] = self::row('session time_zone', (string) ($server['time_zone'] ?? ''), ($server['time_zone'] ?? '') === '+09:00');
            $checks[] = self::row('isolation level', $isolation, str_contains($isolation, 'READ-COMMITTED'));
            $checks[] = self::row('innodb_lock_wait_timeout', (string) ($server['lock_timeout'] ?? ''), (int) ($server['lock_timeout'] ?? 0) === 5);
            $checks[] = self::row('character_set_connection', (string) ($server['cs_connection'] ?? ''), ($server['cs_connection'] ?? '') === 'utf8mb4');

            $schema = Db::selectOne(
                'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS collation
                 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
            ) ?? [];
            $checks[] = self::row(
                'database charset',
                ($schema['cs'] ?? '?') . ' / ' . ($schema['collation'] ?? '?'),
                ($schema['cs'] ?? '') === 'utf8mb4'
            );

            $tables = (int) Db::scalar(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            );
            $checks[] = self::row('tables', (string) $tables, $tables > 0, $tables === 0 ? 'Run: php bin/migrate.php' : '');

            $roundTrip = (string) Db::scalar("SELECT '日本語テスト 𠮷野家' AS s");
            $checks[] = self::row('utf8mb4 round trip', $roundTrip, $roundTrip === '日本語テスト 𠮷野家');
        } catch (Throwable $e) {
            $checks[] = self::row('DB connection', $e->getMessage(), false);
        }

        $checks[] = self::row('debug mode', Config::bool('debug') ? 'on' : 'off', true);
        $checks[] = self::row('mail transport', Config::string('mail.transport'), true);
        $checks[] = self::row(
            'SAPI',
            PHP_SAPI,
            true,
            PHP_SAPI === 'cli-server'
                ? 'Built-in server: requests are handled one at a time on Windows, so concurrency cannot be exercised over HTTP.'
                : ''
        );

        $failed = count(array_filter($checks, static fn (array $c): bool => !$c['ok']));

        return Response::html(
            \App\Core\View::render('pub/diagnostic', [
                'title'  => '環境診断',
                'checks' => $checks,
                'failed' => $failed,
            ])
        );
    }

    /** @return array{label:string, value:string, ok:bool, note:string} */
    private static function row(string $label, string $value, bool $ok, string $note = ''): array
    {
        return ['label' => $label, 'value' => $value, 'ok' => $ok, 'note' => $note];
    }
}
