<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Immutable HTTP request. "with*" methods return modified copies.
 *
 * Raw values (Host header, REMOTE_ADDR) are kept as received. Trusted values
 * (effective host, scheme, client IP, host group) are set as attributes by
 * TrustedHostMiddleware after proxy validation; read them through the
 * dedicated accessors, never from raw headers.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers  lower-case names
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     * @param array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
        private array $attributes = [],
        private readonly string $rawBody = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $rawBody = (string) file_get_contents('php://input');
        $body = $_POST;
        if ($body === [] && str_contains($headers['content-type'] ?? '', 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';

        return new self(
            method: strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            path: self::normalisePath((string) parse_url($uri, PHP_URL_PATH)),
            query: $_GET,
            body: $body,
            headers: $headers,
            cookies: array_filter($_COOKIE, 'is_string'),
            server: $_SERVER,
            rawBody: $rawBody,
        );
    }

    /**
     * Build a request for tests and CLI tooling.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     */
    public static function create(
        string $method,
        string $url,
        array $body = [],
        array $headers = [],
        array $cookies = [],
        array $server = [],
    ): self {
        $parts = parse_url($url) ?: [];
        parse_str($parts['query'] ?? '', $query);
        $normalisedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalisedHeaders[strtolower($name)] = $value;
        }
        if (isset($parts['host']) && !isset($normalisedHeaders['host'])) {
            $normalisedHeaders['host'] = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        $server += [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTPS' => ($parts['scheme'] ?? 'http') === 'https' ? 'on' : 'off',
        ];

        /** @var array<string, mixed> $query */
        return new self(
            method: strtoupper($method),
            path: self::normalisePath($parts['path'] ?? '/'),
            query: $query,
            body: $body,
            headers: $normalisedHeaders,
            cookies: $cookies,
            server: $server,
        );
    }

    private static function normalisePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethodSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function server(string $key): mixed
    {
        return $this->server[$key] ?? null;
    }

    public function remoteAddress(): string
    {
        $address = $this->server['REMOTE_ADDR'] ?? '';

        return is_string($address) ? $address : '';
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /** Effective host after proxy validation (set by TrustedHostMiddleware). */
    public function host(): ?string
    {
        $host = $this->attributes['host'] ?? null;

        return is_string($host) ? $host : null;
    }

    /** Explicit port the request arrived on (set by TrustedHostMiddleware), if any. */
    public function port(): ?int
    {
        $port = $this->attributes['port'] ?? null;

        return is_int($port) ? $port : null;
    }

    public function hostGroup(): ?HostGroup
    {
        $group = $this->attributes['host_group'] ?? null;

        return $group instanceof HostGroup ? $group : null;
    }

    public function isSecure(): bool
    {
        $scheme = $this->attributes['scheme'] ?? null;
        if (is_string($scheme)) {
            return $scheme === 'https';
        }
        $https = $this->server['HTTPS'] ?? 'off';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    public function clientIp(): string
    {
        $ip = $this->attributes['client_ip'] ?? null;

        return is_string($ip) ? $ip : $this->remoteAddress();
    }

    public function wantsJson(): bool
    {
        if ($this->hostGroup()?->prefersJson() === true) {
            return true;
        }
        $accept = $this->header('accept', '') ?? '';

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}
