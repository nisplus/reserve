<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain PHP templates. render() produces the inner content and wraps it in a
 * layout; renderPartial() returns just the fragment.
 */
final class View
{
    private static string $templateDir = '';

    /** @var array<string, mixed> Values available to every template. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = [], string $layout = 'layouts/public'): string
    {
        $content = self::renderPartial($template, $data);
        return self::renderPartial($layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    public static function renderPartial(string $template, array $data = []): string
    {
        $file = self::dir() . DIRECTORY_SEPARATOR
              . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template} ({$file})");
        }

        // extract() into a scope that only holds $file and the merged data, so
        // a template variable cannot clobber the machinery around it.
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $render($file, self::$shared + $data);
    }

    private static function dir(): string
    {
        if (self::$templateDir === '') {
            self::$templateDir = APP_ROOT . DIRECTORY_SEPARATOR . 'templates';
        }
        return self::$templateDir;
    }
}
