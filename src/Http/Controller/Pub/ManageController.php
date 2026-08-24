<?php

declare(strict_types=1);

namespace App\Http\Controller\Pub;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Repository\BookingRepository;
use App\Service\CancellationService;
use App\Service\TokenService;

/**
 * Self-service view and cancel, addressed by the e-mailed token.
 *
 * The token IS the authentication: whoever has the URL is treated as the
 * booking's owner. That is why no page ever prints it back out, and why the
 * done page after booking shows only the reference code.
 */
final class ManageController
{
    /** GET /manage/{token} - show the booking behind the token. */
    public function show(Request $request): Response
    {
        $booking = $this->loadBooking($request->route('token'));

        return Response::html(View::render('pub/manage_show', [
            'title'   => '予約内容の確認',
            'booking' => $booking,
            'token'   => $request->route('token'),
        ]));
    }

    /** POST /manage/{token}/cancel - cancel, then PRG back to the same page. */
    public function cancel(Request $request): Response
    {
        Csrf::verify($request);
        $token = $request->route('token');

        $result = (new CancellationService())->cancelByToken($token);

        if ($result['already_cancelled']) {
            Flash::info('この予約は既にキャンセル済みです。');
        } else {
            Flash::success('キャンセルを受け付けました。確認メールをお送りしています。');
        }
        return Response::redirect('/manage/' . $token);
    }

    /** @return array<string, mixed> */
    private function loadBooking(string $rawToken): array
    {
        $booking = (new BookingRepository())->findByTokenHash(TokenService::hashToken($rawToken));
        if ($booking === null) {
            throw new NotFoundException('お探しの予約は見つかりませんでした。URLをお確かめください。');
        }
        return $booking;
    }
}
