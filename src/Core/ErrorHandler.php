<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Turns warnings into exceptions and renders uncaught throwables.
 * In debug mode the trace is shown; otherwise the user sees a neutral page and
 * the detail goes to the PHP error log.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function install(bool $debug): void
    {
        self::$debug = $debug;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new ErrorException(
                    $error['message'], 0, $error['type'], $error['file'], $error['line']
                ));
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        error_log(sprintf(
            "%s: %s in %s:%d\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, sprintf(
                "\n%s: %s\n  at %s:%d\n\n%s\n",
                $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
            ));
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        if (self::$debug) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title>';
            echo '<style>body{font:14px/1.6 monospace;margin:2rem;background:#fff6f6}'
               . 'h1{font-size:1.1rem;color:#b00}pre{background:#fff;border:1px solid #f0c0c0;'
               . 'padding:1rem;overflow:auto}</style>';
            printf('<h1>%s</h1><p>%s</p><p>%s:%d</p><pre>%s</pre>',
                e($e::class), e($e->getMessage()), e($e->getFile()), $e->getLine(),
                e($e->getTraceAsString()));
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>エラー</title>'
               . '<p>システムエラーが発生しました。時間をおいて再度お試しください。</p>';
        }
        exit(1);
    }
}
