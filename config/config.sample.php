<?php
/**
 * Copy this file to config.php and fill in the real values.
 * config.php is ignored by version control; this sample is committed.
 */

declare(strict_types=1);

return [
    // Show stack traces in the browser. MUST be false in production.
    'debug' => true,

    // No trailing slash. Used to build absolute URLs in e-mails, which have no
    // request to derive them from. Include the subdirectory when the app is
    // not at the domain root: 'https://example.com/booking'.
    'base_url' => 'http://127.0.0.1:8000',

    'db' => [
        // 127.0.0.1 rather than localhost: on Windows the name can resolve to
        // IPv6 ::1 and stall or fail.
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=booking;charset=utf8mb4',
        'user' => 'booking_app',
        'pass' => 'CHANGE_ME',
        // CLI only (bin/migrate.php, bin/seed.php). The application account is
        // deliberately limited to DML, so schema changes need a separate login.
        'admin' => ['user' => 'root', 'pass' => ''],
    ],

    'mail' => [
        // 'file' writes .eml files to storage/mail; 'smtp' actually sends.
        'transport' => 'file',
        'from'      => ['address' => 'noreply@example.test', 'name' => '予約事務局'],
        'admin_to'  => 'admin@example.test',
        'file'      => ['dir' => __DIR__ . '/../storage/mail'],
        'smtp'      => [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',   // 'tls' (STARTTLS) | 'ssl' | 'none'
            'username'   => '',
            'password'   => '',
            'timeout'    => 10,
        ],
    ],

    // Travel time between bookings. When the gap between one booking's end
    // and the next one's start is `minutes` or less (0 disables the check),
    // the applicant is told 移動時間を考慮すると、この予約は間に合いません.
    //   block = false: warning popup on the confirmation screen; they may
    //                  continue anyway.
    //   block = true:  the application is refused outright (enforced inside
    //                  the booking transaction, and promotion from the
    //                  waitlist refuses such gaps too).
    // Note: back-to-back slots (gap 0) fall under this check even though the
    // overlap rule itself allows them.
    'travel_buffer' => [
        'minutes' => 15,
        'block'   => false,
    ],

    'waitlist' => [
        // Promote from the waitlist automatically when seats free up: oldest
        // candidate whose party fits the gap, repeated until nothing fits
        // (first-fit; a too-large group at the head is passed over, order is
        // otherwise preserved). Off by default so an operator can keep that
        // pass-over decision, and cancellations in general, under human review.
        'auto_promote' => false,
    ],

    'session' => [
        'idle_timeout'     => 1800,   // 30 min
        'absolute_timeout' => 28800,  // 8 h
    ],

    // Public booking POSTs allowed per IP per window.
    'rate_limit' => ['window' => 60, 'max' => 10],
];
