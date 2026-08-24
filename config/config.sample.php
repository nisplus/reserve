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

    'waitlist' => [
        // Promote the head of the waitlist automatically when a seat frees up.
        // Off by default: an admin decides, because the head of the queue may
        // need more seats than were released.
        'auto_promote' => false,
    ],

    'session' => [
        'idle_timeout'     => 1800,   // 30 min
        'absolute_timeout' => 28800,  // 8 h
    ],

    // Public booking POSTs allowed per IP per window.
    'rate_limit' => ['window' => 60, 'max' => 10],
];
