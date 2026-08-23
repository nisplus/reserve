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
};
