<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Single PDO connection plus the transaction helper the booking logic relies on.
 *
 * The XAMPP my.ini this project develops against has two defaults that would
 * silently corrupt data, so every connection overrides them:
 *
 *   - character_set_server is unset, i.e. latin1. Japanese text written to a
 *     table that did not spell out utf8mb4 would be destroyed.
 *   - sql_mode lacks STRICT_TRANS_TABLES, so an over-long VARCHAR or an
 *     out-of-range party_size would be truncated with only a warning.
 *
 * The isolation level is READ COMMITTED rather than MariaDB's REPEATABLE READ
 * default. Under REPEATABLE READ a plain SELECT reads the snapshot taken when
 * the transaction started, so a booking another transaction committed a moment
 * ago would be invisible and the overlap check would wave it through.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Nesting depth so services can compose without a nested-transaction error. */
    private static int $transactionDepth = 0;

    /** Set only by CLI scripts that need DDL. Never enabled from a web request. */
    private static bool $useAdmin = false;

    private const MAX_ATTEMPTS = 3;

    /**
     * Switch to the privileged account configured under db.admin. Migrations and
     * seeding need CREATE/DROP/TRUNCATE, which the application account does not
     * have. Refuses to run outside the CLI.
     */
    public static function useAdminCredentials(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Admin database credentials are CLI-only.');
        }
        self::$useAdmin = true;
        self::reset();
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn  = Config::string('db.dsn');
        $user = Config::string('db.user');
        $pass = Config::string('db.pass');

        if (self::$useAdmin) {
            $user = (string) Config::get('db.admin.user', $user);
            $pass = (string) Config::get('db.admin.pass', '');
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Native prepared statements. With emulation on, the driver
                // interpolates values itself and the type handling gets loose.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            $hint = DIRECTORY_SEPARATOR === '\\'
                ? 'Start it with C:\pleiades\xampp\mysql_start.bat (or the XAMPP control panel).'
                : 'Start it with e.g. sudo systemctl start mariadb.';
            throw new RuntimeException(
                'Database connection failed. Is the database server running? '
                . $hint . ' See README.md. Detail: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        // DATETIME columns hold JST wall-clock time; keep the session in step.
        $pdo->exec("SET SESSION time_zone = '+09:00'");
        // my.ini says 50 seconds, far too long to keep a web request waiting.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        self::$pdo = $pdo;
        return $pdo;
    }

    /** Drop the connection. Used by CLI workers that fork-and-reconnect. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$transactionDepth = 0;
    }

    /**
     * Run $work inside a transaction, retrying on deadlock and lock-wait
     * timeout. The callback must be safe to run more than once: it may be
     * rolled back and replayed from the top.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        if (self::$transactionDepth > 0) {
            // Already inside a transaction: join it rather than nesting, so the
            // caller's rollback still covers this work.
            self::$transactionDepth++;
            try {
                return $work(self::pdo());
            } finally {
                self::$transactionDepth--;
            }
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $pdo = self::pdo();
            $pdo->beginTransaction();
            self::$transactionDepth = 1;

            try {
                $result = $work($pdo);
                $pdo->commit();
                self::$transactionDepth = 0;
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::$transactionDepth = 0;

                if ($e instanceof PDOException
                    && self::isRetryable($e)
                    && $attempt < self::MAX_ATTEMPTS
                ) {
                    // Exponential backoff with jitter so retrying peers do not
                    // collide again in lockstep: ~10ms, ~20ms.
                    usleep((10_000 << ($attempt - 1)) + random_int(0, 5_000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Deadlock (40001 / 1213) or lock wait timeout (1205). */
    public static function isRetryable(PDOException $e): bool
    {
        if ($e->getCode() === '40001') {
            return true;
        }
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1213 || $driverCode === 1205;
    }

    /** Unique constraint violation (23000 / 1062). */
    public static function isDuplicateKey(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** CHECK constraint violation (MariaDB 4025). */
    public static function isCheckViolation(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 4025;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return int Rows affected
     */
    public static function execute(string $sql, array $params = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
