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

    /*
     * What this deployment is called. Everything here is shown to the public,
     * so it belongs in config.php (which version control ignores) rather than
     * in the code - the repository should carry a booking system, not one
     * festival's name.
     */
    'site' => [
        // The heading on the public programme list. Example: '〇〇フェス2026'.
        'name' => '体験予約',

        // The header link and the browser tab, which want the word 予約 in a
        // way the heading does not: '〇〇フェス2026 予約'. Written out rather
        // than composed from 'name', because composing gives '体験予約 予約'
        // on an install that has not set one. Empty falls back to 'name'.
        'title' => '',
        // What goes in the 【】 of every mail subject. Empty falls back to
        // 'name'. Example: '〇〇フェス予約'.
        'mail_prefix' => '',

        // The domain to ask applicants to allow through their spam filter.
        // Empty leaves that advice out of the page entirely, which is right
        // when it has not been decided yet - advice naming no domain only
        // teaches people to ignore the footer.
        'mail_domain' => '',
    ],

    /*
     * Display names for companies.area. The stored values stay english
     * (east/south/north/main) so shared URLs keep working; only the labels
     * differ per site, so only the labels are configured. Anything left out
     * falls back to a generic name.
     */
    'areas' => [
        'east'  => '東エリア',
        'south' => '南エリア',
        'north' => '北エリア',
        'main'  => '本館',
    ],

    /*
     * Buttons for narrowing the day-of availability board by what a
     * programme IS, rather than whose it is.
     *
     * A 種別 column on events would be the right home for this, and there
     * isn't one - adding it would mean filling it in for every programme
     * already entered. These words are matched against the title instead,
     * which works because titles end in what they are: 工場見学ツアー,
     * 製品体験ワークショップ. The vocabulary belongs to the festival and not
     * to the software, which is why it sits here beside the area names.
     *
     * Leave it empty and the buttons do not appear; ?q= still takes any word.
     */
    'vacancy_keywords' => ['見学', '体験', 'ワークショップ', '展示', '説明会'],

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
    // overlap rule itself allows them - EXCEPT between two events of the same
    // company, where there is nowhere to travel to and the buffer never
    // applies at any gap.
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
