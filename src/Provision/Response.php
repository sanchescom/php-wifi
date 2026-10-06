<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Closure;

/**
 * What a handler answers with, as plain data: a framework can turn it into
 * its own response object, and plain PHP can {@see self::send()} it.
 *
 * $after is work to do once the answer is on its way. The setup page needs
 * it: joining a network takes the hotspot down, and with it the connection
 * the answer would have travelled over.
 */
final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?Closure $after = null,
    ) {
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public static function html(string $body, int $status = 200, ?Closure $after = null): self
    {
        return new self(
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $body,
            $after,
        );
    }

    public static function redirect(string $location): self
    {
        return new self(302, ['Location' => $location, 'Cache-Control' => 'no-store'], '');
    }

    /**
     * Sends the answer complete, with its length, so the client has all of
     * it and stops waiting — and only then runs {@see self::$after}.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        header('Content-Length: ' . strlen($this->body));
        header('Connection: close');

        echo $this->body;

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();

        if ($this->after !== null) {
            ignore_user_abort(true);
            set_time_limit(0);
            ($this->after)();
        }
    }
}
