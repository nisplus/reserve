<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Service\VacancyService;

/**
 * The day's availability, for anyone who looks - no sign-in.
 *
 * Four dials, independent of each other:
 *
 *   view    now | sessions             what question is being answered
 *   display page | signage | embed     who is reading it, and on what
 *   none    1 | 0                      whether ✕ is worth the room it takes
 *   date                               which day
 *
 * The three displays differ in one thing: what they do when there is more
 * than a screenful.
 *
 *   page     the full public page. Headings, explanation, both tabs.
 *   signage  a wall. Nobody operates it, so it pages itself through the
 *            programmes with meta refresh and a page number in the URL, with
 *            no JavaScript at all - it keeps working on a limited set-top
 *            browser and picks itself up after a power cut.
 *   embed    an iframe on somebody else's site, or a phone. The box it sits
 *            in scrolls, so there is nothing to page: every row is there at
 *            once and the reader moves at their own speed.
 *
 * signage and embed share a template and a stylesheet. They are the same
 * board; only the paging differs, and splitting them would mean fixing the
 * board twice.
 */
final class VacancyController
{
    private const DEFAULT_PER_PAGE = 8;

    /**
     * Seconds a wall rests on one page before moving to the next.
     *
     * Reading time, not freshness: the data is re-read on the way past, but
     * what sets the number is how long somebody needs to take in a screenful
     * from across a room.
     */
    private const DEFAULT_INTERVAL = 30;

    /**
     * Seconds before a screen with nothing to page to re-reads itself.
     *
     * Much longer, because nothing visible happens: a screen with one page is
     * not advancing, it is blinking. Reports are typed in by hand and arrive
     * minutes apart at best, and anything genuinely out of date says so after
     * ninety minutes - so a slower reload costs nothing.
     */
    private const DEFAULT_RELOAD = 120;

    /** How often the full public page re-reads itself, in seconds. */
    private const PAGE_REFRESH = 120;

    public function index(Request $request): Response
    {
        $date = VacancyService::dateFrom($request->query('date'));
        $view = $request->query('view') === 'sessions' ? 'sessions' : 'now';

        $display = match ($request->query('display')) {
            'signage' => 'signage',
            'embed'   => 'embed',
            default   => 'page',
        };

        $service = new VacancyService();

        if ($view === 'sessions') {
            $rows = $service->forSessions(
                $date,
                null,
                // The office sometimes wants the whole day to check its work.
                $request->query('past') !== '1',
            );
        } else {
            $rows = $service->forEvents($date);
        }

        // Before the page is sliced, or hiding ✕ would leave gaps in the
        // pages rather than fewer of them.
        if ($request->query('none') === '0') {
            $rows = $service->withoutFull($rows);
        }

        return $display === 'page'
            ? $this->page($rows, $date, $view)
            : $this->board($request, $rows, $date, $view, $display);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function page(array $rows, string $date, string $view): Response
    {
        return Response::html(View::render('pub/vacancy', [
            'title'   => '当日の空き状況',
            'rows'    => $rows,
            'date'    => $date,
            'view'    => $view,
            'asOf'    => date('H:i'),
            'refresh' => self::PAGE_REFRESH,
        ], 'layouts/public'));
    }

    /**
     * The board: a wall display, or the same thing inside somebody's page.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function board(Request $request, array $rows, string $date, string $view, string $display): Response
    {
        /*
         * A wall of dashes is not worth the room it takes from the rows that
         * say something, and nobody can press anything to skip past it - so
         * unreported entries are left off the board only.
         */
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['report'] !== null
                || ($row['fallback'] ?? null) !== null
        ));

        $rows = (new VacancyService())->sortByAvailability($rows);

        $interval = $this->bounded($request->queryInt('interval', self::DEFAULT_INTERVAL), 5, 600);
        $reload   = $this->bounded($request->queryInt('reload', self::DEFAULT_RELOAD), 10, 3600);

        if ($display === 'embed') {
            // Nothing to page to - the box scrolls. It still re-reads itself,
            // because an embedded board left open all day should not still be
            // showing the morning.
            return $this->render($rows, $date, $view, 'embed', 1, 1, $reload, null);
        }

        $perPage = $this->bounded($request->queryInt('per', self::DEFAULT_PER_PAGE), 1, 40);
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = $this->bounded($request->queryInt('page', 1), 1, $pages);

        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        /*
         * The refresh target is the NEXT page, wrapping at the end. That one
         * line is the whole paging mechanism: no script, no state, and a
         * reload from any cause lands somewhere valid.
         */
        $next = $page >= $pages ? 1 : $page + 1;
        $query = http_build_query(array_filter([
            'display'  => 'signage',
            'view'     => $view === 'sessions' ? 'sessions' : null,
            'date'     => $date === VacancyService::today() ? null : $date,
            'none'     => $request->query('none') === '0' ? '0' : null,
            'per'      => $perPage === self::DEFAULT_PER_PAGE ? null : $perPage,
            'interval' => $interval === self::DEFAULT_INTERVAL ? null : $interval,
            'reload'   => $reload === self::DEFAULT_RELOAD ? null : $reload,
            'page'     => $next > 1 ? $next : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->render(
            $slice,
            $date,
            $view,
            'signage',
            $page,
            $pages,
            // One page means nothing moves, so the only reason to come back is
            // the data - and that arrives by hand, slowly.
            $pages > 1 ? $interval : $reload,
            url('/vacancy') . ($query !== '' ? '?' . $query : '')
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function render(
        array $rows,
        string $date,
        string $view,
        string $display,
        int $page,
        int $pages,
        int $interval,
        ?string $nextUrl,
    ): Response {
        // renderPartial, not render: the board is its own document from
        // <html> down. Nothing of the public layout belongs on a wall, and an
        // iframe should carry the board, not a second site header.
        return Response::html(View::renderPartial('pub/vacancy_signage', [
            'rows'     => $rows,
            'date'     => $date,
            'view'     => $view,
            'display'  => $display,
            'asOf'     => date('H:i'),
            'page'     => $page,
            'pages'    => $pages,
            'interval' => $interval,
            'nextUrl'  => $nextUrl,
        ]));
    }

    private function bounded(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
