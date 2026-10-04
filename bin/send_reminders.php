<?php

declare(strict_types=1);

/**
 * The nightly reminder run.
 *
 * What it does depends on the mode set in the admin screen, which is the point
 * of having a mode at all:
 *
 *   off       - nothing, and says so. The state a release ships in.
 *   approval  - queues nothing. Mails the office that there is a day waiting
 *               to be approved, so forgetting to look is not silent.
 *   auto      - queues the day's reminders.
 *
 * Running it twice is harmless: recipients() excludes everyone who already has
 * a reminder queued against one of that day's bookings.
 *
 * Usage:
 *   php bin/send_reminders.php                  tomorrow, per the configured mode
 *   php bin/send_reminders.php --date=2026-10-10
 *   php bin/send_reminders.php --dry-run        report only, queue nothing
 *
 * Cron (the hour is the "前日の朝" the office chose):
 *   0 7 * * *  cd /path/to/app && php bin/send_reminders.php
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Settings;
use App\Mail\MailDispatcher;
use App\Repository\MailQueueRepository;
use App\Service\ReminderService;

$date = ReminderService::defaultDate();
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m) === 1) {
        $date = $m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        exit("Unknown argument: {$arg}\n");
    }
}

$service = new ReminderService();

if (!Settings::reminderEnabled()) {
    echo "reminder: disabled in settings; nothing to do\n";
    exit(0);
}

$recipients = $service->recipients($date, Settings::reminderIncludesWaitlisted());
$count = count($recipients);

printf(
    "reminder: %s / %s / %d 件\n",
    $date,
    Settings::reminderSendsItself() ? 'auto' : 'approval',
    $count
);

if ($count === 0) {
    echo "reminder: nobody to remind (already sent, or no bookings that day)\n";
    exit(0);
}

if ($dryRun) {
    foreach ($recipients as $person) {
        printf("  %-40s %d 件\n", $person['email'], count($person['bookings']));
    }
    echo "reminder: dry run, nothing queued\n";
    exit(0);
}

// --- approval: tell the office, send nothing ------------------------------
if (!Settings::reminderSendsItself()) {
    $adminTo = Config::string('mail.admin_to');
    if ($adminTo === '') {
        echo "reminder: approval mode but mail.admin_to is empty; nobody can be told\n";
        exit(0);
    }

    $url = Config::url('/admin/reminders?date=' . rawurlencode($date));
    (new MailQueueRepository())->enqueue(
        $adminTo,
        null,
        '【' . Config::mailPrefix() . '】リマインドの確認をお願いします（' . jp_date($date) . '）',
        "{$date} のリマインドが {$count} 件、送信待ちです。\n\n"
        . "承認制の設定のため、管理画面で送信ボタンを押すまで送られません。\n"
        . "中止になった回があれば、画面でチェックを外してから送信してください。\n\n"
        . "{$url}\n",
        null,
        MailQueueRepository::REMINDER,
    );
    MailDispatcher::tryProcessPending();
    echo "reminder: approval mode; asked {$adminTo} to review {$count}\n";
    exit(0);
}

// --- auto: queue them -----------------------------------------------------
$queued = $service->enqueue(
    $recipients,
    $date,
    (string) (Settings::get(Settings::REMINDER_NOTICE) ?? '')
);

echo "reminder: queued {$queued}\n";

$adminTo = Config::string('mail.admin_to');
if ($adminTo !== '') {
    (new MailQueueRepository())->enqueue(
        $adminTo,
        null,
        '【' . Config::mailPrefix() . '】リマインドを送信しました（' . jp_date($date) . '）',
        "{$date} のリマインドを {$queued} 件、送信キューに積みました。\n"
        . "送信結果はメール送信キュー（種別「リマインド」）でご確認いただけます。\n",
        null,
        MailQueueRepository::REMINDER,
    );
}

exit(0);
