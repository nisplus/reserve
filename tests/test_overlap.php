<?php

declare(strict_types=1);

/**
 * TimeRange overlap semantics - the pure-logic half of the duplicate rule.
 * The SQL predicate (s.starts_at < :end AND :start < s.ends_at) implements the
 * same half-open convention; the DB side is exercised by test_capacity.php and
 * the concurrency scenarios.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\TimeRange;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$failures = 0;
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'OK  ' : 'NG  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$range = static fn (string $s, string $e) => TimeRange::fromStrings("2026-10-01 {$s}", "2026-10-01 {$e}");

// Plain overlap, both directions.
$assert($range('10:00', '11:00')->overlaps($range('10:30', '11:30')), 'partial overlap detected');
$assert($range('10:30', '11:30')->overlaps($range('10:00', '11:00')), 'partial overlap is symmetric');

// Containment and identity.
$assert($range('10:00', '12:00')->overlaps($range('10:30', '11:00')), 'contained range overlaps');
$assert($range('10:00', '11:00')->overlaps($range('10:00', '11:00')), 'identical range overlaps');

// The half-open boundary: back-to-back slots must NOT overlap. This is the
// case that lets one person book 10:00-10:45 and 10:45-11:30 together.
$assert(!$range('10:00', '10:45')->overlaps($range('10:45', '11:30')), 'adjacent slots do not overlap');
$assert(!$range('10:45', '11:30')->overlaps($range('10:00', '10:45')), 'adjacency is symmetric');

// Clearly apart.
$assert(!$range('10:00', '10:45')->overlaps($range('12:00', '12:45')), 'disjoint ranges do not overlap');

// One-minute intrusion across the boundary.
$assert($range('10:00', '10:46')->overlaps($range('10:45', '11:30')), 'one minute past the boundary overlaps');

// Cross-midnight range (the reason starts_at/ends_at are DATETIME, not TIME).
$night = TimeRange::fromStrings('2026-10-01 22:00', '2026-10-02 01:00');
$assert($night->overlaps(TimeRange::fromStrings('2026-10-02 00:30', '2026-10-02 02:00')), 'cross-midnight overlap detected');
$assert(!$night->overlaps(TimeRange::fromStrings('2026-10-02 01:00', '2026-10-02 02:00')), 'cross-midnight adjacency respected');

// Invalid construction refused.
try {
    $range('11:00', '10:00');
    $assert(false, 'end before start is rejected');
} catch (InvalidArgumentException) {
    $assert(true, 'end before start is rejected');
}

echo $failures === 0 ? "overlap: all OK\n" : "overlap: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
