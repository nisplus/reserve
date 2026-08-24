<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin wrapper over the superglobals with UTF-8 validation on the way in.
 */
final class Request
{
    /** @var array<string, string> Route placeholders, filled in by the Router. */
    private array $routeParams = [];

    /**
     * URL prefix the application is mounted under ('' at the domain root,
     * '/booking' when deployed to https://host/booking/). Set by fromGlobals();
     * read by the app_base() template helper and Response::redirect().
     */
    private static string $basePath = '';

    private function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path   = is_string($path) ? rawurldecode($path) : '/';

        self::$basePath = self::deriveBasePath((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = self::stripBasePath($path, self::$basePath);

        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        return new self($method, $path);
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /**
     * Where is the application mounted, as seen from the browser?
     *
     * SCRIPT_NAME is the executed front controller as a URL path - e.g.
     * /booking/public/index.php on a shared host where the whole repository
     * sits in a subdirectory and the root .htaccess rewrites into public/.
     * Its directory, minus the /public segment the rewrite added, is the
     * public base. At the domain root this collapses to ''.
     */
    public static function deriveBasePath(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }
        if ($dir === '/' || $dir === '.' || $dir === '') {
            return '';
        }
        return rtrim($dir, '/');
    }

    /**
     * Reduce the request path to what the route table expects: no mount
     * prefix, and no /public or /index.php remnants from URLs that hit the
     * front controller directly (no rewrite, or a hand-typed address).
     */
    public static function stripBasePath(string $path, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = (string) substr($path, strlen($basePath));
        }
        foreach (['/public/index.php', '/public', '/index.php'] as $prefix) {
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = (string) substr($path, strlen($prefix));
            }
        }
        return $path === '' ? '/' : $path;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, string $default = ''): string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeInt(string $key): int
    {
        return (int) ($this->routeParams[$key] ?? 0);
    }

    public function query(string $key, string $default = ''): string
    {
        return self::clean($_GET[$key] ?? null) ?? $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key, '');
        return $value === '' || !preg_match('/^-?\d+$/', $value) ? $default : (int) $value;
    }

    public function post(string $key, string $default = ''): string
    {
        return self::clean($_POST[$key] ?? null) ?? $default;
    }

    public function postInt(string $key, int $default = 0): int
    {
        $value = $this->post($key, '');
        return $value === '' || !preg_match('/^-?\d+$/', $value) ? $default : (int) $value;
    }

    public function has(string $key): bool
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** All GET parameters, cleaned. @return array<string, string> */
    public function allQuery(): array
    {
        $out = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $cleaned = self::clean($value);
                if ($cleaned !== null) {
                    $out[$key] = $cleaned;
                }
            }
        }
        return $out;
    }

    /**
     * Reject anything that is not valid UTF-8 rather than letting mojibake or a
     * filter-evading byte sequence through, and strip control characters that
     * have no business in form input.
     */
    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        // Keep tab, LF and CR; drop the rest of C0 plus DEL.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }
}
