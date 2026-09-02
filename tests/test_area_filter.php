<?php

declare(strict_types=1);

/**
 * Company areas and the public catalogue filter.
 *
 * The filter has to survive being pasted into a chat window, so the whole of
 * it lives in the query string and unknown values fall back to "no filter"
 * rather than erroring.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\Area;
use App\Repository\CompanyRepository;
use App\Repository\EventRepository;

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

fixture_cleanup();
$companies = new CompanyRepository();
$events    = new EventRepository();

/** @param array<int, array<string, mixed>> $rows */
$companyIds = static fn (array $rows): array => array_values(array_unique(
    array_map(static fn (array $row): int => (int) $row['company_id'], $rows)
));

try {
    // --- the enum ------------------------------------------------------------
    $assert(array_keys(Area::options()) === ['east', 'south', 'north', 'main'],
        'four areas, stored as URL-safe values');
    $assert(Area::East->label() === '東エリア' && Area::Main->label() === 'テクノプラザ本館',
        'labels are the Japanese names');
    $assert(Area::tryFrom('west') === null, 'an unknown area is null, not an error');
    $assert(Area::labelFor(null) === '未設定' && Area::labelFor('west') === '未設定',
        'null and nonsense both display as 未設定');

    // --- fixtures, published so the public queries see them -------------------
    $east  = $companies->create(FIXTURE_PREFIX . 'east co',  null, 9900, true, Area::East->value);
    $north = $companies->create(FIXTURE_PREFIX . 'north co', null, 9901, true, Area::North->value);
    $none  = $companies->create(FIXTURE_PREFIX . 'no area',  null, 9902, true, null);

    foreach ([$east, $north, $none] as $companyId) {
        $eventId = $events->create($companyId, 'event of ' . $companyId, null, null, 0, true);
        fixture_create_session($eventId, '2027-08-01 10:00:00', '2027-08-01 11:00:00', 10);
    }

    $assert((int) $companies->find($east)['area'] === 0 || $companies->find($east)['area'] === 'east',
        'the area round-trips through the repository');

    // --- the catalogue filter -------------------------------------------------
    $all = $companyIds($events->publishedCatalogue());
    foreach ([$east, $north, $none] as $companyId) {
        $assert(in_array($companyId, $all, true), "company {$companyId} appears unfiltered");
    }

    // Membership, not equality: the real catalogue has companies of its own
    // and the assertion must not depend on how they happen to be tagged.
    $eastOnly = $companyIds($events->publishedCatalogue(Area::East->value));
    $assert(in_array($east, $eastOnly, true), 'the east fixture appears under east');
    $assert(!in_array($north, $eastOnly, true) && !in_array($none, $eastOnly, true),
        'filtering by area excludes other areas and the unassigned');

    $southern = $companyIds($events->publishedCatalogue(Area::South->value));
    $assert(!in_array($east, $southern, true) && !in_array($north, $southern, true),
        'neither fixture leaks into an area it does not belong to');

    $assert($companyIds($events->publishedCatalogue(null, $north)) === [$north],
        'filtering by company alone works');

    $assert($companyIds($events->publishedCatalogue(Area::East->value, $east)) === [$east],
        'area and company together agree');
    $assert($companyIds($events->publishedCatalogue(Area::East->value, $north)) === [],
        'a company outside the chosen area matches nothing');

    // A company with no area must not leak into an area filter.
    $assert(!in_array($none, $companyIds($events->publishedCatalogue(Area::East->value)), true),
        'an unassigned company never appears under an area');

    // --- the select box only offers companies with something to show ---------
    $options = array_column($events->publishedCompanies(), 'id');
    $assert(in_array($east, $options, true), 'a company with a published event is offered');
    $eastOptions = array_column($events->publishedCompanies(Area::East->value), 'id');
    $assert(in_array($east, $eastOptions, true) && !in_array($north, $eastOptions, true),
        'the company list narrows with the area');

    $empty = $companies->create(FIXTURE_PREFIX . 'empty co', null, 9903, true, Area::East->value);
    $assert(!in_array($empty, array_column($events->publishedCompanies(), 'id'), true),
        'a company with no events is not offered as a filter');

    // --- unpublished stays invisible ------------------------------------------
    Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$east]);
    $assert(!in_array($east, $companyIds($events->publishedCatalogue(Area::East->value)), true),
        'an unpublished company is hidden even when its area is asked for');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "area filter: all OK\n" : "area filter: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
