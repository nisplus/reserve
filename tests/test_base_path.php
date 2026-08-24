<?php

declare(strict_types=1);

/**
 * Mount-prefix handling: the app must work at the domain root AND under a
 * subdirectory (https://host/booking/), where SCRIPT_NAME carries the prefix
 * that the route table knows nothing about.
 *
 * The 404 this guards against looks like a bug in the app but is really a
 * path-arithmetic mistake: every route misses because the request path still
 * has /booking (or /public, or /index.php) glued to the front.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Request;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$failures = 0;
$assert = static function (mixed $actual, mixed $expected, string $label) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? 'OK  ' : 'NG  ') . $label
        . ($ok ? '' : sprintf('  (expected %s, got %s)', var_export($expected, true), var_export($actual, true)))
        . "\n";
    if (!$ok) {
        $failures++;
    }
};

// --- deriveBasePath: where is the app mounted? -----------------------------
// DocumentRoot -> public/, app at the domain root.
$assert(Request::deriveBasePath('/index.php'), '', 'root mount, DocumentRoot=public');
// Whole repo under the web root, root .htaccess rewrote into public/.
$assert(Request::deriveBasePath('/public/index.php'), '', 'root mount, repo in web root');
// Subdirectory, both layouts.
$assert(Request::deriveBasePath('/booking/index.php'), '/booking', 'subdir, DocumentRoot=public');
$assert(Request::deriveBasePath('/booking/public/index.php'), '/booking', 'subdir, repo in web root');
$assert(Request::deriveBasePath('/~user/reserve/public/index.php'), '/~user/reserve', 'tilde user dir');

// --- stripBasePath: reduce the URL to what routes.php declares -------------
$assert(Request::stripBasePath('/booking/events/1', '/booking'), '/events/1', 'prefix stripped');
$assert(Request::stripBasePath('/booking', '/booking'), '/', 'bare mount point becomes /');
$assert(Request::stripBasePath('/booking/', '/booking'), '/', 'mount point with slash becomes /');
$assert(Request::stripBasePath('/events/1', ''), '/events/1', 'root mount unchanged');

// Direct hits on the front controller, with no rewrite in play.
$assert(Request::stripBasePath('/index.php', ''), '/', 'index.php alone');
$assert(Request::stripBasePath('/index.php/events/1', ''), '/events/1', 'index.php path info');
$assert(Request::stripBasePath('/public/events/1', ''), '/events/1', 'public/ prefix dropped');
$assert(Request::stripBasePath('/public/index.php/admin', ''), '/admin', 'public/index.php prefix dropped');
$assert(Request::stripBasePath('/booking/public/index.php/admin', '/booking'), '/admin', 'subdir + public + index.php');

// A path that merely starts with the same letters must survive intact.
$assert(Request::stripBasePath('/publicity/1', ''), '/publicity/1', 'lookalike segment not eaten');

echo $failures === 0 ? "base path: all OK\n" : "base path: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
