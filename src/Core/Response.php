<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * The last response sent, for the command-line driver to look at.
     *
     * header() does nothing under the CLI SAPI and headers_list() always
     * comes back empty, so bin/request.php could report a status but never a
     * Location - and a redirect is mostly its Location. Two faults that
     * reached the office were a redirect pointing at the wrong place, and
     * neither was testable while the only way to see one was a browser.
     *
     * Set on every send; read by bin/request.php and the tests that drive it.
     */
    private static ?self $lastSent = null;

    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** The last response this process sent, or null if it has sent none. */
    public static function lastSent(): ?self
    {
        return self::$lastSent;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * 303 by default: after a successful POST we want the browser to follow up
     * with a GET, so a reload or Back does not resubmit the form.
     *
     * Site-relative targets get the mount prefix added here, in one place, so
     * controllers keep saying redirect('/admin') and it works whether the app
     * lives at the domain root or under /booking.
     */
    public static function redirect(string $location, int $status = 303): self
    {
        if (str_starts_with($location, '/')) {
            $location = Request::basePath() . $location;
        }
        return new self($status, '', ['Location' => $location]);
    }

    /** @param array<string, string> $headers */
    public static function raw(string $body, int $status, array $headers): self
    {
        return new self($status, $body, $headers);
    }

    public function send(): void
    {
        self::$lastSent = $this;

        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
