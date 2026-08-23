<?php

declare(strict_types=1);

/**
 * Front controller. Also acts as the router script for `php -S`.
 *
 * Only this directory is exposed: config/, storage/ and src/ live above the
 * document root and are unreachable over HTTP.
 */

// Let the built-in server deliver static assets itself instead of booting the
// whole application for every stylesheet.
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($requestPath) && $requestPath !== '/') {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR
            . ltrim(str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($requestPath)), DIRECTORY_SEPARATOR);
        if (is_file($candidate) && !str_ends_with(strtolower($candidate), '.php')) {
            return false;
        }
    }
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\SessionManager;
use App\Core\View;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;

$request = Request::fromGlobals();
$router  = new Router();

/** @var callable(Router): void $registerRoutes */
$registerRoutes = require APP_ROOT . '/src/routes.php';
$registerRoutes($router);

try {
    $matched = $router->match($request);
    $request->withRouteParams($matched['params']);

    if (($matched['options']['auth'] ?? false) === true) {
        SessionManager::start();
        if (!Auth::check()) {
            Flash::error('ログインしてください。');
            Response::redirect('/admin/login')->send();
            return;
        }
    }

    $handler = $matched['handler'];
    if (is_array($handler)) {
        [$class, $method] = $handler;
        $handler = [new $class(), $method];
    }

    $response = $handler($request);
    if (!$response instanceof Response) {
        throw new RuntimeException('Handler did not return a Response.');
    }
    $response->send();
} catch (NotFoundException $e) {
    Response::html(
        View::render('pub/error', ['title' => 'ページが見つかりません', 'message' => $e->getMessage()]),
        404
    )->send();
} catch (ValidationException $e) {
    // Reached only when a handler did not catch it, e.g. a failed CSRF check.
    Response::html(
        View::render('pub/error', ['title' => '送信できませんでした', 'message' => $e->getMessage()]),
        400
    )->send();
}
