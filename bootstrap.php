<?php
/**
 * Shared entry point for both the web front controller and the CLI scripts.
 * Registers the autoloader, loads configuration, installs error handling.
 */

declare(strict_types=1);

// Idempotent: entry points may include this without coordinating with each
// other (bin/request.php loads it, then loads the front controller, which
// loads it again).
if (defined('APP_ROOT')) {
    return;
}

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PHP 8.2 or newer is required, running " . PHP_VERSION . "\n");
    exit(1);
}

define('APP_ROOT', __DIR__);

/**
 * PSR-4 style autoloader for the App\ namespace, without Composer.
 *
 * Production runs on Linux where filenames are case-sensitive, while NTFS is
 * not. tests/test_autoload_case.php guards against a mismatch that would only
 * surface after deployment.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
    $file = APP_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once APP_ROOT . '/src/helpers.php';

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    $message = "config/config.php is missing. Copy config/config.sample.php to "
             . "config/config.php and fill in the database credentials.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit(1);
}

App\Core\Config::load(require $configFile);

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::install(App\Core\Config::bool('debug'));
