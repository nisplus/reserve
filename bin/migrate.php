<?php

declare(strict_types=1);

/**
 * Applies db/migrations/*.sql in filename order, recording what has run in
 * schema_migrations so re-running is a no-op.
 *
 * Written in PHP rather than piping into mysql.exe: PowerShell 5.1 has no `<`
 * input redirection, so `mysql -u root db < schema.sql` is a parser error, and
 * `Get-Content | mysql` invites encoding damage.
 *
 * Usage:
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list migrations and whether they ran
 *   php bin/migrate.php --fresh    drop every table, then apply from scratch
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

Db::useAdminCredentials();

$options = array_slice($argv, 1);
$fresh   = in_array('--fresh', $options, true);
$status  = in_array('--status', $options, true);

$migrationDir = APP_ROOT . '/db/migrations';
$files = glob($migrationDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    exit("No migration files found in db/migrations.\n");
}

Db::execute(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

if ($fresh) {
    dropAllTables();
    Db::execute(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename   VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

$applied = [];
foreach (Db::select('SELECT filename, applied_at FROM schema_migrations') as $row) {
    $applied[(string) $row['filename']] = (string) $row['applied_at'];
}

if ($status) {
    foreach ($files as $file) {
        $name = basename($file);
        printf("%-30s %s\n", $name, $applied[$name] ?? '(pending)');
    }
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    echo "Applying {$name} ...\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "  cannot read {$file}\n");
        exit(1);
    }

    foreach (splitStatements($sql) as $index => $statement) {
        try {
            Db::pdo()->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, sprintf(
                "  FAILED on statement %d: %s\n  %s\n",
                $index + 1,
                $e->getMessage(),
                mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 200)
            ));
            exit(1);
        }
    }

    Db::execute('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
    $ran++;
    echo "  ok\n";
}

echo $ran === 0 ? "Already up to date.\n" : "Applied {$ran} migration(s).\n";

$tables = (int) Db::scalar(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
);
echo "Tables in database: {$tables}\n";

/**
 * Split a script into individual statements.
 *
 * Deliberately simple: it strips full-line `--` comments and splits on a
 * semicolon at end of line. That is sufficient here because the migrations
 * contain no stored routines and no semicolons inside string literals. If a
 * migration ever needs either, switch to an explicit DELIMITER convention
 * rather than making this smarter.
 *
 * @return array<int, string>
 */
function splitStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $kept[] = $line;
    }

    $statements = [];
    foreach (explode(";\n", implode("\n", $kept) . "\n") as $chunk) {
        $trimmed = trim($chunk);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
    }
    return $statements;
}

function dropAllTables(): void
{
    $tables = Db::select(
        'SELECT TABLE_NAME AS name FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
    );
    if ($tables === []) {
        return;
    }

    echo "--fresh: dropping " . count($tables) . " table(s)\n";
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        Db::pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table['name']) . '`');
    }
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
}
