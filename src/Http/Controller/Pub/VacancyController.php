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
 * Three dials, independent of each other:
 *
 *   view    now | sessions   what question is being answered
 *   display normal | signage who is reading it
 *   date                     which day
 *
 * The signage variant is the one with the unusual constraint: nobody operates
 * it. It therefore pages itself through the companies using meta refresh and a
 * page number in the URL, with no JavaScript at all, so it keeps working on a
 * limited set-top browser and picks itself up after a power cut.
 */
final class VacancyController
{
    private const DEFAULT_PER_PAGE = 8;
    private const DEFAULT_INTERVAL = 20;

    /** How often a normal browser re-reads the page, in seconds. */
    private const REFRESH_SECONDS = 60;

    public function index(Request $request): Response
    {
        $date = VacancyService::dateFrom($request->query('date'));
        $view = $request->query('view') === 'sessions' ? 'sessions' : 'now';
        $signage = $request->query('display') === 'signage';

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

        return $signage
            ? $this->signage($request, $rows, $date, $view)
            : $this->normal($rows, $date, $view);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function normal(array $rows, string $date, string $view): Response
    {
        return Response::html(View::render('pub/vacancy', [
            'title'   => '当日の空き状況',
            'rows'    => $rows,
            'date'    => $date,
            'view'    => $view,
            'asOf'    => date('H:i'),
            'refresh' => self::REFRESH_SECONDS,
        ], 'layouts/public'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function signage(Request $request, array $rows, string $date, string $view): Response
    {
        /*
         * A wall of dashes is not worth the room it takes from the rows that
         * say something, and nobody can press anything to skip past it - so
         * unreported entries are left off this screen only.
         */
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['report'] !== null
                || ($row['fallback'] ?? null) !== null
        ));

        $perPage = $this->bounded($request->queryInt('per', self::DEFAULT_PER_PAGE), 1, 40);
        $interval = $this->bounded($request->queryInt('interval', self::DEFAULT_INTERVAL), 5, 600);

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
            'per'      => $perPage === self::DEFAULT_PER_PAGE ? null : $perPage,
            'interval' => $interval === self::DEFAULT_INTERVAL ? null : $interval,
            'page'     => $next > 1 ? $next : null,
        ]));

        // renderPartial, not render: the signage screen is its own document
        // from <html> down. Nothing of the public layout belongs on a wall.
        return Response::html(View::renderPartial('pub/vacancy_signage', [
            'rows'     => $slice,
            'date'     => $date,
            'view'     => $view,
            'asOf'     => date('H:i'),
            'page'     => $page,
            'pages'    => $pages,
            'interval' => $interval,
            'nextUrl'  => url('/vacancy') . ($query !== '' ? '?' . $query : ''),
        ]));
    }

    private function bounded(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
