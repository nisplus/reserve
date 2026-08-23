<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;

final class EventController
{
    /** Catalogue, grouped by company. */
    public function index(Request $request): Response
    {
        $events = new EventRepository();
        $companies = $events->groupByCompany($events->publishedCatalogue());

        return Response::html(View::render('pub/events_index', [
            'title'     => 'イベント一覧',
            'companies' => $companies,
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
