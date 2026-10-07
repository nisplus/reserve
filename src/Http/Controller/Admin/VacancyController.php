<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Auth;
use App\Core\Authz;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\VacancyLevel;
use App\Exception\NotFoundException;
use App\Repository\VacancyRepository;
use App\Service\VacancyService;

/**
 * Registering the day's availability.
 *
 * Two ways in, because the information arrives two ways:
 *
 *   quick     - chat says "we're ◎ right now", one click per booth
 *   transcribe- a photograph of the printed session sheet with the ticket
 *               counts stuck to it, so one programme's whole day at once
 *
 * Company accounts see and may write only their own, which is the existing
 * Authz boundary rather than a new one. Nothing here writes to any table but
 * vacancy_reports.
 */
final class VacancyController
{
    private const RECENT = 15;

    /** GET /admin/vacancy?date=&event= */
    public function index(Request $request): Response
    {
        $date = VacancyService::dateFrom($request->query('date'));
        $companyId = Authz::scopeCompanyId();
        $eventId = $request->queryInt('event');

        $service = new VacancyService();
        $repo = new VacancyRepository();

        // The transcription screen: one programme, its whole day.
        $sessions = [];
        $event = null;
        if ($eventId > 0) {
            $event = $this->loadEvent($eventId, $companyId);
            $sessions = $service->forSessions($date, $companyId, false, $eventId, false);
        }

        return Response::html(View::render('admin/vacancy', [
            'title'    => '当日の空き状況',
            'date'     => $date,
            'events'   => $service->forEvents($date, $companyId, false),
            'event'    => $event,
            'sessions' => $sessions,
            'levels'   => VacancyLevel::options(),
            'recent'   => $repo->recent(self::RECENT, $companyId),
            'isOffice' => $companyId === null,
        ], 'layouts/admin'));
    }

    /**
     * POST /admin/vacancy - one report, from the quick screen.
     *
     * No confirmation popup: this gets pressed many times on the day, and a
     * dialog every time is a dialog nobody reads. Pressing the wrong mark is
     * undone by pressing the right one, because reports are appended and the
     * newest wins.
     */
    public function store(Request $request): Response
    {
        Csrf::verify($request);

        $date = VacancyService::dateFrom($request->post('date'));
        $companyId = Authz::scopeCompanyId();

        $eventId = $request->postInt('event_id');
        $this->loadEvent($eventId, $companyId);

        $level = VacancyLevel::tryFrom($request->post('level'));
        if ($level === null) {
            Flash::error('空き状況の値が正しくありません。');
            return Response::redirect('/admin/vacancy?date=' . urlencode($date));
        }

        $remaining = $this->remaining($request->post('remaining'));
        $sessionId = $request->postInt('session_id') ?: null;
        if ($sessionId !== null) {
            $this->assertSessionBelongs($sessionId, $eventId);
        }

        (new VacancyRepository())->add(
            $eventId,
            $sessionId,
            VacancyLevel::reconcile($level, $remaining)->value,
            $remaining,
            null,
            Auth::actor(),
        );

        Flash::success('登録しました。');
        return Response::redirect('/admin/vacancy?date=' . urlencode($date));
    }

    /**
     * POST /admin/vacancy/sessions - a whole programme's day, from the photo.
     *
     * Rows with no mark are skipped rather than defaulted: a session missing
     * from the photograph must stay unreported, not become a guess. The ones
     * that were filled in go in together, so the screen cannot half-save.
     */
    public function storeSessions(Request $request): Response
    {
        Csrf::verify($request);

        $date = VacancyService::dateFrom($request->post('date'));
        $companyId = Authz::scopeCompanyId();

        $eventId = $request->postInt('event_id');
        $this->loadEvent($eventId, $companyId);

        $levels = (array) ($_POST['level'] ?? []);
        $remainings = (array) ($_POST['remaining'] ?? []);

        $reports = [];
        foreach ($levels as $sessionId => $raw) {
            $sessionId = (int) $sessionId;
            $level = is_string($raw) ? VacancyLevel::tryFrom($raw) : null;
            if ($sessionId <= 0 || $level === null) {
                continue; // not filled in on this row
            }
            $this->assertSessionBelongs($sessionId, $eventId);

            $remaining = $this->remaining((string) ($remainings[$sessionId] ?? ''));
            $reports[] = [
                'session_id' => $sessionId,
                'level' => VacancyLevel::reconcile($level, $remaining)->value,
                'remaining' => $remaining,
            ];
        }

        if ($reports === []) {
            Flash::info('入力された行がありませんでした。');
            return Response::redirect($this->backUrl($date, $eventId));
        }

        $actor = Auth::actor();
        $repo = new VacancyRepository();
        $count = Db::transaction(static function () use ($reports, $eventId, $actor, $repo): int {
            foreach ($reports as $report) {
                $repo->add(
                    $eventId,
                    $report['session_id'],
                    $report['level'],
                    $report['remaining'],
                    null,
                    $actor,
                );
            }
            return count($reports);
        });

        Flash::success("{$count} 件の開催回を登録しました。");
        return Response::redirect($this->backUrl($date, $eventId));
    }

    private function backUrl(string $date, int $eventId): string
    {
        return '/admin/vacancy?date=' . urlencode($date) . '&event=' . $eventId;
    }

    /** Empty stays empty; anything non-numeric is treated as not entered. */
    private function remaining(string $raw): ?int
    {
        $raw = trim($raw);
        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * The programme, if this account may touch it.
     *
     * 404 rather than 403 for another company's id, as everywhere else in the
     * admin - an id should not be probeable for existence.
     *
     * @return array<string, mixed>
     */
    private function loadEvent(int $eventId, ?int $companyId): array
    {
        $row = Db::selectOne(
            'SELECT e.id, e.title, e.venue, c.id AS company_id, c.name AS company_name
               FROM events e
               JOIN companies c ON c.id = e.company_id
              WHERE e.id = ?',
            [$eventId]
        );

        if ($row === null || ($companyId !== null && (int) $row['company_id'] !== $companyId)) {
            throw new NotFoundException('お探しの体験プログラムは見つかりませんでした。');
        }
        return $row;
    }

    /** A session id posted for the wrong programme is a tampered form. */
    private function assertSessionBelongs(int $sessionId, int $eventId): void
    {
        $owner = Db::scalar('SELECT event_id FROM event_sessions WHERE id = ?', [$sessionId]);
        if ($owner === null || (int) $owner !== $eventId) {
            throw new NotFoundException('お探しの開催回は見つかりませんでした。');
        }
    }
}
