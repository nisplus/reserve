<?php

declare(strict_types=1);

/**
 * Barrier-synchronised worker for concurrency testing.
 *
 * This exists because HTTP cannot exercise races here: the built-in server
 * handles one request at a time on Windows (PHP_CLI_SERVER_WORKERS is
 * POSIX-only), so any number of parallel browser tabs are quietly serialised.
 * This worker bypasses the controllers and calls the services directly.
 *
 * Process start-up costs 50-150ms, so simply launching N workers would spread
 * them out and nothing would collide. Instead every worker does its expensive
 * preparation (autoload, config, DB connect) first, then spin-waits until the
 * shared --start-at instant, then fires - all workers hit the transaction
 * within microseconds of each other.
 *
 * Usage:
 *   php bin/concurrency_test.php --action=book    --email=u1@x.test --session=3 --party=1 [--no-waitlist] [--start-at=T]
 *   php bin/concurrency_test.php --action=cancel  --booking=45 [--start-at=T]
 *   php bin/concurrency_test.php --action=promote --booking=45 [--start-at=T]
 *
 * --start-at is a unix timestamp (float ok). Output is one machine-parseable
 * line; see the constants below. Exit code 0 for an expected outcome
 * (including "rejected as designed"), 1 for an unexpected error.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Db;
use App\Exception\DuplicateBookingException;
use App\Exception\SessionFullException;
use App\Exception\ValidationException;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\WaitlistService;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

$action  = (string) ($options['action'] ?? 'book');
$startAt = (float) ($options['start-at'] ?? 0);

// ---- expensive preparation, BEFORE the barrier ----------------------------
Db::scalar('SELECT 1'); // connect + session setup now, not inside the window

// ---- barrier ---------------------------------------------------------------
if ($startAt > 0) {
    if ($startAt - microtime(true) > 60) {
        fwrite(STDERR, "start-at is more than 60s away; refusing to spin that long.\n");
        exit(1);
    }
    while (microtime(true) < $startAt) {
        usleep(200);
    }
}

// ---- fire -------------------------------------------------------------------
try {
    switch ($action) {
        case 'book':
            $result = (new BookingService())->book(
                (int) ($options['session'] ?? 0),
                strtolower(trim((string) ($options['email'] ?? ''))),
                (string) ($options['name'] ?? 'CT Worker'),
                (int) ($options['party'] ?? 1),
                !isset($options['no-waitlist'])
            );
            echo $result['status']->value === 'confirmed'
                ? "CONFIRMED {$result['reference_code']}\n"
                : "WAITLISTED {$result['waitlist_seq']} {$result['reference_code']}\n";
            break;

        case 'cancel':
            $result = (new CancellationService())->cancelById((int) ($options['booking'] ?? 0), 'test:concurrency');
            echo $result['already_cancelled'] ? "ALREADY\n" : "CANCELLED\n";
            break;

        case 'promote':
            (new WaitlistService())->promote((int) ($options['booking'] ?? 0), 'test:concurrency');
            echo "PROMOTED\n";
            break;

        default:
            fwrite(STDERR, "Unknown --action: {$action}\n");
            exit(1);
    }
} catch (DuplicateBookingException) {
    echo "DUPLICATE\n";
} catch (SessionFullException) {
    echo "FULL\n";
} catch (ValidationException $e) {
    echo 'REJECTED ' . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n";
} catch (Throwable $e) {
    echo 'ERROR ' . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n";
    exit(1);
}
