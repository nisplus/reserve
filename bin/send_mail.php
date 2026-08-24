<?php

declare(strict_types=1);

/**
 * Drain the mail queue.
 *
 * The web flow already tries to send right after each commit; this script is
 * the reliable path that picks up anything that slipped through (transport
 * down, request died after commit) and the natural cron entry point in
 * production.
 *
 * Usage:
 *   php bin/send_mail.php               up to 50 pending messages
 *   php bin/send_mail.php --limit=200
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Mail\MailDispatcher;

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$result = (new MailDispatcher())->processPending($limit);

printf(
    "Transport: %s\nSent %d, failed %d, skipped %d.\n",
    Config::string('mail.transport', 'file'),
    $result['sent'],
    $result['failed'],
    $result['skipped']
);

$left = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'");
if ($left > 0) {
    printf("Still pending: %d (raise --limit or run again).\n", $left);
}
$parked = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed'");
if ($parked > 0) {
    printf("Parked as failed: %d - inspect mail_queue.last_error.\n", $parked);
}

exit($result['failed'] > 0 ? 1 : 0);
