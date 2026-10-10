<?php

declare(strict_types=1);

/**
 * Which areas a visitor is offered as a filter.
 *
 * The site names four areas and the ENUM column carries all four, because one
 * of them is held open for companies that have not joined yet. A filter for
 * an area nobody is in is a button whose only possible answer is "nothing
 * here", so the ones on screen come from the companies instead - and when an
 * area gets its first company the button appears on its own, which is the
 * half of this worth a test: nobody will remember to come back and add it.
 *
 * The company form is the exception and is asserted as one. It is where an
 * area gets its first company, so it has to offer the empty ones.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\Area;
use App\Repository\CompanyRepository;

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

$companies = new CompanyRepository();

/*
 * Every area the real companies sit in, parked for the duration. This suite
 * has to see areas empty, and the only way to do that is to empty them - so
 * it puts them back in the finally, whatever happens in between.
 */
$parked = Db::select('SELECT id, area FROM companies WHERE area IS NOT NULL');

fixture_cleanup();

try {
    Db::execute('UPDATE companies SET area = NULL WHERE area IS NOT NULL');

    $assert($companies->areasInUse() === [],
        'with nobody in any area, no area is offered - the filter bar has nothing to say');

    $one = fixture_create_company('areaA');
    Db::execute('UPDATE companies SET area = ?, is_published = 1 WHERE id = ?', ['east', $one]);

    $assert(array_keys($companies->areasInUse()) === ['east'],
        'an area with a company in it is offered');
    $assert($companies->areasInUse()['east'] === Area::East->label(),
        'under the name the rest of the site calls it');
    $assert(!array_key_exists('north', $companies->areasInUse()),
        'and an area held open for a company that has not joined yet is not');

    // The point of the whole thing: it follows the data by itself.
    $two = fixture_create_company('areaB');
    Db::execute('UPDATE companies SET area = ?, is_published = 1 WHERE id = ?', ['north', $two]);

    $assert(array_key_exists('north', $companies->areasInUse()),
        'and the day somebody moves into it, its button appears with nobody having to add it');

    // Order is the site's, not whatever the database hands back.
    Db::execute('UPDATE companies SET area = ?, is_published = 1 WHERE id = ?', ['main', $one]);
    $three = fixture_create_company('areaC');
    Db::execute('UPDATE companies SET area = ?, is_published = 1 WHERE id = ?', ['south', $three]);

    $order = array_keys($companies->areasInUse());
    $assert($order === ['south', 'north', 'main'],
        'listed in the order the site names its areas, not the order rows came back');

    // An area whose only company is hidden is not an area a visitor can reach.
    Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$two]);
    $assert(!array_key_exists('north', $companies->areasInUse()),
        'an unpublished company does not hold an area open on the public filters');
    $assert(array_key_exists('north', $companies->areasInUse(false)),
        'though the office can still ask for every area that has anybody in it');

    // The definitions stay put. Emptying an area must not need a migration,
    // and filling it again must not need one either.
    $assert(count(Area::options()) === 4,
        'the four areas are still defined, because this is display and not schema');
} finally {
    Db::execute('UPDATE companies SET area = NULL WHERE area IS NOT NULL');
    foreach ($parked as $row) {
        Db::execute('UPDATE companies SET area = ? WHERE id = ?', [$row['area'], (int) $row['id']]);
    }
    fixture_cleanup();
}

echo $failures === 0 ? "area in use: all OK\n" : "area in use: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
