<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\NotFoundException;

/**
 * Pattern-matching router. Placeholders in a path are rewritten to named
 * capture groups: {id} matches digits, {token} a 64-char hex string,
 * {ref} the public reference code.
 *
 * A route may carry 'auth' => true, which the front controller checks before
 * dispatching. That is the whole middleware story - anything richer would be
 * more machinery than seven admin screens justify.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable|array, options:array}> */
    private array $routes = [];

    private const PLACEHOLDERS = [
        '{id}'    => '(?P<id>[0-9]+)',
        '{token}' => '(?P<token>[0-9a-f]{64})',
        '{ref}'   => '(?P<ref>[0-9A-Za-z]{12})',
    ];

    /**
     * @param callable|array{0:class-string, 1:string} $handler
     * @param array<string, mixed> $options
     */
    public function add(string $method, string $path, callable|array $handler, array $options = []): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $this->compile($path),
            'handler' => $handler,
            'options' => $options,
        ];
    }

    /** @param array<string, mixed> $options */
    public function get(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $path, callable|array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    /**
     * @return array{handler:callable|array, params:array<string,string>, options:array<string,mixed>}
     * @throws NotFoundException
     */
    public function match(Request $request): array
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return ['handler' => $route['handler'], 'params' => $params, 'options' => $route['options']];
        }

        throw new NotFoundException($pathMatched
            ? 'そのURLはこの操作方法では利用できません。'
            : 'ページが見つかりません。');
    }

    private function compile(string $path): string
    {
        $pattern = preg_quote($path, '#');
        foreach (self::PLACEHOLDERS as $placeholder => $group) {
            $pattern = str_replace(preg_quote($placeholder, '#'), $group, $pattern);
        }
        return '#^' . $pattern . '$#u';
    }
}
