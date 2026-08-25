<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Exception\DuplicateBookingException;
use App\Exception\NotFoundException;
use App\Exception\SessionFullException;
use App\Exception\TravelBufferException;
use App\Exception\ValidationException;
use App\Mail\MailDispatcher;
use App\Repository\BookingRepository;
use App\Repository\EventSessionRepository;
use App\Service\BookingService;

/**
 * Public booking flow: form -> confirm -> commit -> done.
 *
 * The commit redirects (303) to the done page, so reload and Back cannot
 * resubmit. Seat counts shown along the way are advisory only - the truth is
 * decided inside BookingService under the session lock, which is why every
 * screen repeats that the displayed numbers may differ from the outcome.
 */
final class BookingController
{
    private const PARTY_MAX = 20; // chk_bookings_party mirrors this

    /** GET /sessions/{id}/apply - the application form. */
    public function apply(Request $request): Response
    {
        $session = $this->loadSession($request->routeInt('id'));

        return Response::html(View::render('pub/booking_apply', [
            'title'   => '申し込み：' . $session['event_title'],
            'session' => $session,
            'errors'  => [],
            'old'     => [],
        ]));
    }

    /** POST /sessions/{id}/confirm - validate and show the confirmation screen. */
    public function confirm(Request $request): Response
    {
        Csrf::verify($request);
        $session = $this->loadSession($request->routeInt('id'));

        $input = $this->validateInput($request);
        if ($input instanceof Response) {
            return $input; // the re-rendered form
        }

        return Response::html(View::render('pub/booking_confirm', [
            'title'       => '申し込み内容の確認',
            'session'     => $session,
            'input'       => $input,
            'willWait'    => (int) $session['seats_left'] < (int) $input['party_size'],
            // Travel-time proximity to a booking this address already holds.
            // Advisory here (it drives the warning panel and the popup); the
            // enforcing check runs inside the booking transaction.
            'travelWarn'  => (new BookingService())->travelBufferWarning((string) $input['email'], $session),
            'travelBlock' => BookingService::travelBufferBlocks(),
        ]));
    }

    /** POST /bookings - the actual booking. */
    public function store(Request $request): Response
    {
        // First thing, before any validation: the brake must not depend on
        // the request being otherwise well-formed.
        if (!RateLimiter::allow(
            'booking:' . $request->ip(),
            Config::int('rate_limit.window', 60),
            Config::int('rate_limit.max', 10),
        )) {
            return Response::html(View::render('pub/error', [
                'title'   => '送信回数の上限に達しました',
                'message' => '短時間に多くのお申し込みが送信されました。1分ほど待ってから、もう一度お試しください。',
            ]), 429);
        }

        Csrf::verify($request);

        // The session id travels in a hidden field; validate it like any input.
        $session = $this->loadSession($request->postInt('session_id'));

        $input = $this->validateInput($request);
        if ($input instanceof Response) {
            return $input;
        }

        try {
            $result = (new BookingService())->book(
                (int) $session['id'],
                (string) $input['email'],
                (string) $input['name'],
                (int) $input['party_size'],
            );
        } catch (DuplicateBookingException | SessionFullException | TravelBufferException | ValidationException $e) {
            // All of these are user-correctable outcomes, not errors: show the
            // form again with the reason on top and the input preserved.
            return $this->renderForm($session, ['_top' => $e->getMessage()], [
                'email'      => (string) $input['email'],
                'name'       => (string) $input['name'],
                'party_size' => (string) $input['party_size'],
            ]);
        }

        // The booking is committed; get its mail out now rather than at the
        // next CLI run. Best effort - failure leaves it queued.
        MailDispatcher::tryProcessPending();

        Flash::success('お申し込みを受け付けました。確認メールをお送りしています。');
        return Response::redirect('/bookings/done/' . $result['reference_code']);
    }

    /** GET /bookings/done/{ref} - completion. Shows no cancel token, by design. */
    public function done(Request $request): Response
    {
        $booking = (new BookingRepository())->findByReference($request->route('ref'));
        if ($booking === null) {
            throw new NotFoundException('お探しの予約は見つかりませんでした。');
        }

        return Response::html(View::render('pub/booking_done', [
            'title'   => 'お申し込みを受け付けました',
            'booking' => $booking,
        ]));
    }

    /**
     * Session with event context, published and open only. Closed or hidden
     * sessions 404 rather than explain themselves - the URL was either stale
     * or guessed, and the list page tells the live story.
     *
     * @return array<string, mixed>
     */
    private function loadSession(int $sessionId): array
    {
        $session = (new EventSessionRepository())->findWithContext($sessionId, true);
        if ($session === null || (string) $session['status'] !== 'open') {
            throw new NotFoundException('この開催回は現在お申し込みを受け付けていません。');
        }
        return $session;
    }

    /**
     * Shared by confirm and store: hidden fields are still client input, so
     * both steps validate the same way.
     *
     * @return array<string, mixed>|Response Values on success, or the
     *                                       re-rendered form on failure.
     */
    private function validateInput(Request $request): array|Response
    {
        $validator = new Validator();
        $validator->email('email', 'メールアドレス', $request->post('email'));
        $validator->required('name', 'お名前', $request->post('name'))
                  ->maxLength('name', 'お名前', $request->post('name'), 100);
        $validator->intRange('party_size', '参加人数', $request->post('party_size'), 1, self::PARTY_MAX);

        if (!$validator->hasErrors()) {
            return $validator->values();
        }

        $session = $this->loadSession(
            $request->postInt('session_id') ?: $request->routeInt('id')
        );
        return $this->renderForm($session, $validator->errors(), [
            'email'      => $request->post('email'),
            'name'       => $request->post('name'),
            'party_size' => $request->post('party_size'),
        ]);
    }

    /**
     * @param array<string, mixed>  $session
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderForm(array $session, array $errors, array $old): Response
    {
        return Response::html(View::render('pub/booking_apply', [
            'title'   => '申し込み：' . $session['event_title'],
            'session' => $session,
            'errors'  => $errors,
            'old'     => $old,
        ]), 422);
    }
}
