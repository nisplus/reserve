<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Area;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;

final class EventController
{
    /**
     * Catalogue, grouped by company.
     *
     * ?area= and ?company= are the filter, and they are the whole filter
     * state: the page holds nothing in the session, so the address bar after
     * filtering is a link that reproduces exactly this view for anyone it is
     * sent to. Unrecognised values fall back to "no filter" rather than
     * erroring, because these URLs get pasted, truncated and hand-edited.
     */
    public function index(Request $request): Response
    {
        $area = Area::tryFrom($request->query('area'))?->value;
        $companyId = $request->queryInt('company');

        $events = new EventRepository();
        $catalogue = $events->publishedCatalogue($area, $companyId);

        // A company filter that survived but matched nothing (wrong area, or
        // an id that no longer exists) should not silently look like "no
        // events exist" - the view says so instead.
        return Response::html(View::render('pub/events_index', [
            'title'     => 'イベント一覧',
            'companies' => $events->groupByCompany($catalogue),
            'areas'     => Area::options(),
            'companyOptions' => $events->publishedCompanies($area),
            'area'      => $area,
            'companyId' => $companyId,
            'filtered'  => $area !== null || $companyId > 0,
        ]));
    }

    /** Event detail with its sessions, grouped by day. */
    public function show(Request $request): Response
    {
        $eventId = $request->routeInt('id');

        $event = (new EventRepository())->findWithCompany($eventId, true);
        if ($event === null) {
            throw new NotFoundException('お探しのイベントは見つかりませんでした。');
        }

        $sessionRepo = new EventSessionRepository();
        $sessions = $sessionRepo->forEvent($eventId, true);

        return Response::html(View::render('pub/event_show', [
            'title' => (string) $event['title'],
            'event' => $event,
            'days'  => $sessionRepo->groupByDate($sessions),
            'total' => count($sessions),
        ]));
    }
}
