<?php

declare(strict_types=1);

/**
 * HTML-escape for templates.
 *
 * ENT_SUBSTITUTE keeps invalid UTF-8 from collapsing the whole string to "".
 * The null coalesce is required on PHP 8.2, where passing null to
 * htmlspecialchars() is deprecated - nullable columns hit this constantly.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape and turn newlines into <br>. Event descriptions are plain text only;
 * allowing any HTML through would mean maintaining a sanitiser.
 */
function enl(mixed $value): string
{
    return nl2br(e($value), false);
}

/**
 * Site-relative URL, prefixed with wherever the application is mounted.
 *
 * Templates say url('/events/1'); at the domain root that stays /events/1,
 * and under https://host/booking/ it becomes /booking/events/1. Writing a
 * bare "/events/1" in an href is the bug this exists to prevent - it would
 * point at the server root and 404 on any subdirectory deployment.
 *
 * The return value is HTML-escaped, so it drops straight into an attribute.
 */
function url(string $path = '/'): string
{
    return e(App\Core\Request::basePath() . $path);
}

/** Format a DATETIME string as "2026-08-20 (木) 10:00". */
function jp_datetime(string $datetime): string
{
    $ts = strtotime($datetime);
    return date('Y-m-d', $ts) . ' (' . jp_weekday($ts) . ') ' . date('H:i', $ts);
}

/** Format a DATETIME string as "10:00". */
function jp_time(string $datetime): string
{
    return date('H:i', strtotime($datetime));
}

/** Format a DATE string as "2026-08-20 (木)". */
function jp_date(string $date): string
{
    $ts = strtotime($date);
    return date('Y-m-d', $ts) . ' (' . jp_weekday($ts) . ')';
}

function jp_weekday(int $timestamp): string
{
    return ['日', '月', '火', '水', '木', '金', '土'][(int) date('w', $timestamp)];
}
