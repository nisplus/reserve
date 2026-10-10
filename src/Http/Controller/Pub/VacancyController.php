<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repository\CompanyRepository;
use App\Domain\Area;
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
     * How many of a programme's upcoming rounds the board shows.
     *
     * The next one plus two. Without a cap this is unbounded, and unbounded
     * here means unusable: fifty-six programmes at seven rounds each is four
     * hundred cards, a wall that comes round again every twenty-six minutes,
     * where a visitor glancing at it for thirty seconds has a one-in-fifty
     * chance of seeing the booth they asked about.
     */
    private const DEFAULT_ROUNDS = 3;

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

        /*
         * preview=1 puts the day's real programme list on the board with
         * invented marks, so a wall can be judged before the day it is for.
         * Unauthenticated on purpose: the screen it is checked on is the one
         * at the venue, which nobody signs in to. What makes that safe is
         * that it is never quiet about it - the banner is part of the page,
         * not a flag that can be left off.
         *
         * The board only. The public page is prose about real reports, and
         * invented ones underneath it would read as real.
         */
        $preview = $display !== 'page' && $request->query('preview') === '1';

        if ($display !== 'page') {
            /*
             * One list, whatever shape the programmes are. The board used to
             * have the same two tabs as the page, and a visitor had to know
             * which one answered their question; nobody standing in front of
             * a wall is going to find out, because there is nothing to press.
             */
            $rounds = $this->bounded($request->queryInt('rounds', self::DEFAULT_ROUNDS), 1, 99);
            $rows = $preview
                ? $service->previewRows($date, $rounds)
                : $service->boardRows($date, $rounds);
        } elseif ($view === 'sessions') {
            $rows = $service->forSessions(
                $date,
                null,
                // The office sometimes wants the whole day to check its work.
                $request->query('past') !== '1',
            );
        } else {
            $rows = $service->forEvents($date);
        }

        // All the narrowing happens before the page is sliced, or hiding
        // rows would leave gaps in the pages rather than fewer of them.
        $filter = $this->filter($request);
        $rows = $service->narrow($rows, $filter['area'], $filter['words']);
        if (!$filter['full']) {
            $rows = $service->withoutFull($rows);
        }

        return $display === 'page'
            ? $this->page($rows, $date, $view, $filter)
            : $this->board($request, $rows, $date, $view, $display, $preview, $filter);
    }

    /**
     * What the URL asks to be left out.
     *
     * A whole festival is more cards than a screen holds, so the screens that
     * can be aimed at part of it should be. area is the one that pays - four
     * halls, four quarters - and q names what a programme is, in the absence
     * of a column that does.
     *
     * Read in one place so the board, the page and the links they print all
     * agree about what is currently being shown.
     *
     * @return array{area: ?string, words: array<int, string>, q: string, full: bool}
     */
    private function filter(Request $request): array
    {
        $area = trim($request->query('area'));
        $area = Area::tryFrom($area) !== null ? $area : null;

        $q = trim($request->query('q'));

        return [
            'area' => $area,
            // Spaces split, so ?q=見学 体験 asks for either. Full-width ones
            // too: a phone keyboard in Japanese gives those by default.
            'words' => $q === '' ? [] : (preg_split('/[\s\x{3000}]+/u', $q) ?: []),
            'q' => $q,
            // ✕ shown unless asked otherwise. "Full" is an answer, and
            // without it a reader cannot tell a full programme from one
            // nobody has reported on.
            'full' => $request->query('none') !== '0',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{area: ?string, words: array<int, string>, q: string, full: bool} $filter
     */
    private function page(array $rows, string $date, string $view, array $filter): Response
    {
        return Response::html(View::render('pub/vacancy', [
            'title'    => Config::siteName() . ' の空き状況',
            'heading'  => Config::siteName() . ' の空き状況',
            'rows'     => $rows,
            'date'     => $date,
            'view'     => $view,
            'asOf'     => date('H:i'),
            'refresh'  => self::PAGE_REFRESH,
            'filter'   => $filter,
            'keywords' => Config::array('vacancy_keywords'),
            'areas'    => (new CompanyRepository())->areasInUse(),
        ], 'layouts/public'));
    }

    /**
     * The board: a wall display, or the same thing inside somebody's page.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function board(
        Request $request,
        array $rows,
        string $date,
        string $view,
        string $display,
        bool $preview,
        array $filter,
    ): Response {
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

        $note = $preview ? $this->previewNote($date, $rows) : null;

        $rows = (new VacancyService())->sortForBoard($rows);

        $interval = $this->bounded($request->queryInt('interval', self::DEFAULT_INTERVAL), 5, 600);
        $reload   = $this->bounded($request->queryInt('reload', self::DEFAULT_RELOAD), 10, 3600);

        if ($display === 'embed') {
            // Nothing to page to - the box scrolls. It still re-reads itself,
            // because an embedded board left open all day should not still be
            // showing the morning.
            return $this->render($rows, $date, $view, 'embed', 1, 1, $reload, null, $note, $filter);
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
            'date'     => $date === VacancyService::today() ? null : $date,
            'none'     => $filter['full'] ? null : '0',
            'area'     => $filter['area'],
            'q'        => $filter['q'] !== '' ? $filter['q'] : null,
            'rounds'   => $request->queryInt('rounds', self::DEFAULT_ROUNDS) === self::DEFAULT_ROUNDS
                ? null
                : $this->bounded($request->queryInt('rounds', self::DEFAULT_ROUNDS), 1, 99),
            'preview'  => $preview ? '1' : null,
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
            url('/vacancy') . ($query !== '' ? '?' . $query : ''),
            $note,
            $filter,
            $perPage
        );
    }

    /**
     * What the banner says on a rehearsal.
     *
     * It names the day, because a rehearsal of the wrong date is the easy
     * mistake, and it says how many of the programmes on screen the real
     * board will actually carry - which is the thing the office cannot see
     * by looking, and the thing that is wrong when the board comes up empty
     * on the day.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function previewNote(string $date, array $rows): string
    {
        $shown = count($rows);
        $onBoard = count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['on_board'] ?? false) === true
        ));

        $note = jp_date($date) . ' の表示テスト　催事名は実際のものですが、空き状況の記号は架空です';

        if ($onBoard < $shown) {
            $note .= sprintf(
                '　／　本番に出るのは「予約不要」の %d 件だけです（この画面は %d 件）',
                $onBoard,
                $shown
            );
        }
        return $note;
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
        ?string $previewNote = null,
        array $filter = ['area' => null, 'words' => [], 'q' => '', 'full' => true],
        ?int $perPage = null,
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
            'preview'  => $previewNote !== null,
            'previewNote' => $previewNote,
            'heading'  => Config::siteName() . ' の空き状況',
            'filter'   => $filter,
            'keywords' => Config::array('vacancy_keywords'),
            'areas'    => (new CompanyRepository())->areasInUse(),
            'perPage'  => $perPage ?? count($rows),
        ]));
    }

    private function bounded(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
