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
use App\Repository\BookingAttendeeRepository;
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

    // Wide enough not to argue with anyone; 0 would be a typo, not a baby.
    private const AGE_MIN = 0;
    private const AGE_MAX = 120;

    /** GET /sessions/{id}/apply - the application form. */
    public function apply(Request $request): Response
    {
        $session = $this->loadSession($request->routeInt('id'));

        return Response::html(View::render('pub/booking_apply', [
            'title'    => '予約：' . $session['event_title'],
            'session'  => $session,
            'errors'   => [],
            'old'      => [],
            'maxParty' => min((int) $session['max_party_size'] ?: self::PARTY_MAX, self::PARTY_MAX),
        ]));
    }

    /** POST /sessions/{id}/confirm - validate and show the confirmation screen. */
    public function confirm(Request $request): Response
    {
        Csrf::verify($request);
        $session = $this->loadSession($request->routeInt('id'));

        $input = $this->validateInput($request, $session);
        if ($input instanceof Response) {
            return $input; // the re-rendered form
        }

        return Response::html(View::render('pub/booking_confirm', [
            'title'       => '予約内容の確認',
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
                'message' => '短時間に多くのご予約が送信されました。1分ほど待ってから、もう一度お試しください。',
            ]), 429);
        }

        Csrf::verify($request);

        // The session id travels in a hidden field; validate it like any input.
        $session = $this->loadSession($request->postInt('session_id'));

        $input = $this->validateInput($request, $session);
        if ($input instanceof Response) {
            return $input;
        }

        try {
            $result = (new BookingService())->book(
                (int) $session['id'],
                (string) $input['email'],
                (string) $input['name'],
                (int) $input['party_size'],
                companionNames: (array) $input['companions'],
                ages: (array) $input['ages'],
                phone: (string) $input['phone'],
            );
        } catch (DuplicateBookingException | SessionFullException | TravelBufferException | ValidationException $e) {
            // All of these are user-correctable outcomes, not errors: show the
            // form again with the reason on top and the input preserved.
            return $this->renderForm($session, ['_top' => $e->getMessage()], [
                'email'      => (string) $input['email'],
                'phone'      => (string) $input['phone'],
                'name'       => (string) $input['name'],
                'party_size' => (string) $input['party_size'],
                'companions' => $this->postedCompanions($request),
                'ages'       => $this->postedAges($request) + [1 => $request->post('age_1')],
            ]);
        }

        // The booking is committed; get its mail out now rather than at the
        // next CLI run. Best effort - failure leaves it queued.
        MailDispatcher::tryProcessPending();

        Flash::success('ご予約を受け付けました。確認メールをお送りしています。');
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
            'title'     => 'ご予約を受け付けました',
            'booking'   => $booking,
            'attendees' => (new BookingAttendeeRepository())->listFor((int) $booking['id']),
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
            throw new NotFoundException('この開催回は現在ご予約を受け付けていません。');
        }
        // 予約不要 events take no applications. Sessions created before the
        // flag was set still exist, so a bookmarked or guessed apply URL has
        // to be refused here rather than relying on the links being gone.
        if ((int) $session['booking_required'] !== 1) {
            throw new NotFoundException('このイベントは予約不要です。イベントページをご覧ください。');
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
    private function validateInput(Request $request, array $session): array|Response
    {
        // The event's cap, never larger than what the column can hold.
        $maxParty = min((int) $session['max_party_size'] ?: self::PARTY_MAX, self::PARTY_MAX);

        $validator = new Validator();
        $validator->email('email', 'メールアドレス', $request->post('email'));
        $validator->phone('phone', '当日連絡が取れる電話番号', $request->post('phone'));
        $validator->required('name', 'お名前', $request->post('name'))
                  ->maxLength('name', 'お名前', $request->post('name'), 100);
        $validator->intRange('age_1', '年齢', $request->post('age_1'), self::AGE_MIN, self::AGE_MAX);
        $validator->intRange('party_size', '参加人数', $request->post('party_size'), 1, $maxParty);

        // Names and ages for the rest of the party, one pair per extra person.
        // Collected only once the party size itself is known to be sane, so a
        // nonsense number does not also produce a wall of per-person errors.
        $companions = [];
        $ages = [];
        if (!$validator->hasErrors()) {
            $partySize = (int) $validator->value('party_size');
            $names = $this->postedCompanions($request);
            $postedAges = $this->postedAges($request);
            $ages[] = (int) $validator->value('age_1');

            for ($i = 2; $i <= $partySize; $i++) {
                $value = trim($names[$i] ?? '');
                if ($value === '') {
                    $validator->fail("companion_{$i}", "{$i}人目のお名前を入力してください。");
                } elseif (mb_strlen($value) > 100) {
                    $validator->fail("companion_{$i}", "{$i}人目のお名前は100文字以内で入力してください。");
                } else {
                    $companions[] = $value;
                }

                $age = trim($postedAges[$i] ?? '');
                if (!preg_match('/^\d+$/', $age)
                    || (int) $age < self::AGE_MIN || (int) $age > self::AGE_MAX
                ) {
                    $validator->fail("age_{$i}", sprintf(
                        '%d人目の年齢は%d〜%dの範囲で入力してください。',
                        $i,
                        self::AGE_MIN,
                        self::AGE_MAX
                    ));
                } else {
                    $ages[] = (int) $age;
                }
            }
        }

        if (!$validator->hasErrors()) {
            $values = $validator->values();
            $values['companions'] = $companions;
            $values['ages'] = $ages;
            return $values;
        }

        return $this->renderForm($session, $validator->errors(), [
            'email'      => $request->post('email'),
            'phone'      => $request->post('phone'),
            'name'       => $request->post('name'),
            'party_size' => $request->post('party_size'),
            'companions' => $this->postedCompanions($request),
            'ages'       => $this->postedAges($request) + [1 => $request->post('age_1')],
        ]);
    }

    /**
     * age_2 .. age_N from the request, keyed by their number. age_1 is the
     * applicant's and is validated as an ordinary field.
     *
     * @return array<int, string>
     */
    private function postedAges(Request $request): array
    {
        $out = [];
        for ($i = 2; $i <= self::PARTY_MAX; $i++) {
            $value = $request->post("age_{$i}");
            if ($value !== '') {
                $out[$i] = $value;
            }
        }
        return $out;
    }

    /**
     * companion_2 .. companion_N from the request, keyed by their number.
     *
     * Read up to the column's hard limit rather than the posted party size,
     * so a form redisplayed after an error keeps what was typed even if the
     * number was the thing that was wrong.
     *
     * @return array<int, string>
     */
    private function postedCompanions(Request $request): array
    {
        $out = [];
        for ($i = 2; $i <= self::PARTY_MAX; $i++) {
            $value = $request->post("companion_{$i}");
            if ($value !== '') {
                $out[$i] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed>  $session
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderForm(array $session, array $errors, array $old): Response
    {
        return Response::html(View::render('pub/booking_apply', [
            'title'    => '予約：' . $session['event_title'],
            'session'  => $session,
            'errors'   => $errors,
            'old'      => $old,
            'maxParty' => min((int) $session['max_party_size'] ?: self::PARTY_MAX, self::PARTY_MAX),
        ]), 422);
    }
}
