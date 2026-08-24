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

    // --- admin ---------------------------------------------------------------
    $auth = ['auth' => true];

    $router->get('/admin/login',   [App\Http\Controller\Admin\AuthController::class, 'showLogin']);
    $router->post('/admin/login',  [App\Http\Controller\Admin\AuthController::class, 'login']);
    $router->post('/admin/logout', [App\Http\Controller\Admin\AuthController::class, 'logout'], $auth);

    $router->get('/admin', [App\Http\Controller\Admin\DashboardController::class, 'index'], $auth);

    $router->get('/admin/companies',              [App\Http\Controller\Admin\CompanyController::class, 'index'], $auth);
    $router->get('/admin/companies/new',          [App\Http\Controller\Admin\CompanyController::class, 'create'], $auth);
    $router->post('/admin/companies',             [App\Http\Controller\Admin\CompanyController::class, 'store'], $auth);
    $router->get('/admin/companies/{id}/edit',    [App\Http\Controller\Admin\CompanyController::class, 'edit'], $auth);
    $router->post('/admin/companies/{id}',        [App\Http\Controller\Admin\CompanyController::class, 'update'], $auth);
    $router->post('/admin/companies/{id}/delete', [App\Http\Controller\Admin\CompanyController::class, 'delete'], $auth);

    $router->get('/admin/events',              [App\Http\Controller\Admin\EventController::class, 'index'], $auth);
    $router->get('/admin/events/new',          [App\Http\Controller\Admin\EventController::class, 'create'], $auth);
    $router->post('/admin/events',             [App\Http\Controller\Admin\EventController::class, 'store'], $auth);
    $router->get('/admin/events/{id}/edit',    [App\Http\Controller\Admin\EventController::class, 'edit'], $auth);
    $router->post('/admin/events/{id}',        [App\Http\Controller\Admin\EventController::class, 'update'], $auth);
    $router->post('/admin/events/{id}/delete', [App\Http\Controller\Admin\EventController::class, 'delete'], $auth);

    $router->get('/admin/events/{id}/sessions',       [App\Http\Controller\Admin\SessionController::class, 'index'], $auth);
    $router->get('/admin/events/{id}/sessions/new',   [App\Http\Controller\Admin\SessionController::class, 'create'], $auth);
    $router->post('/admin/events/{id}/sessions',      [App\Http\Controller\Admin\SessionController::class, 'store'], $auth);
    $router->get('/admin/events/{id}/sessions/bulk',  [App\Http\Controller\Admin\SessionController::class, 'bulkForm'], $auth);
    $router->post('/admin/events/{id}/sessions/bulk', [App\Http\Controller\Admin\SessionController::class, 'bulkStore'], $auth);
    $router->get('/admin/sessions/{id}/edit',         [App\Http\Controller\Admin\SessionController::class, 'edit'], $auth);
    $router->post('/admin/sessions/{id}',             [App\Http\Controller\Admin\SessionController::class, 'update'], $auth);
    $router->post('/admin/sessions/{id}/delete',      [App\Http\Controller\Admin\SessionController::class, 'delete'], $auth);
};
