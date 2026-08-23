<?php

declare(strict_types=1);

/**
 * Drive the front controller from the command line, without running a server.
 *
 * Handy on its own, and necessary here: the built-in server handles one request
 * at a time on Windows, so nothing is lost by skipping it, and CLI invocation
 * keeps the whole flow inspectable.
 *
 * Usage:
 *   php bin/request.php /                       GET
 *   php bin/request.php /events/1 --text        strip tags from the output
 *   php bin/request.php /bookings --post name=山田 --post email=a@example.test
 *   php bin/request.php /manage/<token> --session=storage/cli-session.json
 *
 * --post implies POST. Repeat it per field. A CSRF token is injected
 * automatically unless --no-csrf is given.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

$argvRest = array_slice($argv, 1);
$path     = '/';
$post     = [];
$query    = [];
$asText   = false;
$noCsrf   = false;
$showHead = false;

foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--post=')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 7), 2), 2, '');
        $post[$k] = $v;
    } elseif ($arg === '--text') {
        $asText = true;
    } elseif ($arg === '--no-csrf') {
        $noCsrf = true;
    } elseif ($arg === '--headers') {
        $showHead = true;
    } elseif (!str_starts_with($arg, '--')) {
        $path = $arg;
    }
}

$parts = parse_url($path);
$requestPath = $parts['path'] ?? '/';
if (isset($parts['query'])) {
    parse_str($parts['query'], $query);
}

$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['REQUEST_URI']    = $path;
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = '127.0.0.1:8000';
$_GET  = $query;
$_POST = $post;

// Sessions need a real session_id; CLI has no cookie to supply one. Reuse a
// fixed id so a login in one invocation is visible in the next.
if (!$noCsrf) {
    session_id('clitestsession');
}

ob_start();
require dirname(__DIR__) . '/bootstrap.php';

if ($post !== [] && !$noCsrf) {
    // Mint a token the same way a rendered form would have.
    $_POST[App\Core\Csrf::FIELD] = App\Core\Csrf::token();
}

require dirname(__DIR__) . '/public/index.php';
$body = (string) ob_get_clean();

if ($showHead) {
    fwrite(STDERR, 'HTTP ' . http_response_code() . "\n");
    foreach (headers_list() as $header) {
        fwrite(STDERR, $header . "\n");
    }
    fwrite(STDERR, str_repeat('-', 40) . "\n");
}

if ($asText) {
    $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $body) ?? $body;
    $body = preg_replace('/<[^>]+>/', ' ', $body) ?? $body;
    $body = html_entity_decode($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
    $body = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $body) ?? $body;
    $body = implode("\n", array_filter(
        array_map('rtrim', explode("\n", $body)),
        static fn (string $line): bool => trim($line) !== ''
    ));
}

echo $body, "\n";
