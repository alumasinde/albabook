<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<Cookie> */
    private array $cookies = [];

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[self::normaliseName($name)] = $value;
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[self::normaliseName($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[self::normaliseName($name)]);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[self::normaliseName($name)] = $value;

        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[self::normaliseName($name)]);

        return $clone;
    }

    public function withCookie(Cookie $cookie): self
    {
        $clone = clone $this;
        $clone->cookies[] = $cookie;

        return $clone;
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(bool $includeBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeaderValue(), false);
            }
        }

        if ($includeBody) {
            echo $this->body;
        }
    }

    private static function normaliseName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
