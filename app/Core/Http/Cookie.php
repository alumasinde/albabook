<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Host-only cookie: no Domain attribute is ever emitted (auth.md §8a).
 */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public ?int $expiresAt = null,
        public bool $secure = true,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
        public string $path = '/',
    ) {
        if (str_starts_with($name, '__Host-') && (!$secure || $path !== '/')) {
            throw new \InvalidArgumentException('__Host- cookies must be Secure with Path=/.');
        }
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('SameSite must be Lax, Strict or None.');
        }
        if (preg_match('/[^\x21-\x7E]|[;,]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Cookie name and value must be cookie-safe tokens.');
        }
    }

    public function toHeaderValue(): string
    {
        $parts = ["{$this->name}={$this->value}", "Path={$this->path}"];
        if ($this->expiresAt !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->expiresAt);
            $parts[] = 'Max-Age=' . max(0, $this->expiresAt - time());
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = "SameSite={$this->sameSite}";

        return implode('; ', $parts);
    }
}
