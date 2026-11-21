<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Core\Http\Request;

/**
 * Only a local absolute path is ever used as a post-login destination, so the
 * `next` parameter cannot become an open redirect.
 */
final class LocalRedirect
{
    public static function next(Request $request, string $fallback = '/'): string
    {
        $next = $request->input('next', $request->query('next'));
        if (!is_string($next) || $next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
            return $fallback;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
            return $fallback;
        }

        return $next;
    }
}
