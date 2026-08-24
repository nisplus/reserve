<?php

declare(strict_types=1);

use App\Core\Router;

/**
 * Route table. Handlers are [Controller::class, 'method'] pairs, instantiated
 * by the front controller. Routes marked ['auth' => true] require an admin session.
 */
return static function (Router $router): void {

    // --- diagnostics -----------------------------------------------------
    $router->get('/_diag', [App\Http\Controller\Pub\DiagnosticController::class, 'index']);

    // --- public catalogue -------------------------------------------------
    $router->get('/',            [App\Http\Controller\Pub\EventController::class, 'index']);
    $router->get('/events/{id}', [App\Http\Controller\Pub\EventController::class, 'show']);

    // --- public booking flow ----------------------------------------------
    $router->get('/sessions/{id}/apply',    [App\Http\Controller\Pub\BookingController::class, 'apply']);
    $router->post('/sessions/{id}/confirm', [App\Http\Controller\Pub\BookingController::class, 'confirm']);
    $router->post('/bookings',              [App\Http\Controller\Pub\BookingController::class, 'store']);
    $router->get('/bookings/done/{ref}',    [App\Http\Controller\Pub\BookingController::class, 'done']);

    // --- self-service via e-mailed token ------------------------------------
    $router->get('/manage/{token}',          [App\Http\Controller\Pub\ManageController::class, 'show']);
    $router->post('/manage/{token}/cancel',  [App\Http\Controller\Pub\ManageController::class, 'cancel']);
};
