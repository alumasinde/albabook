<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Core\Http\Response;

/**
 * Standard JSON envelope for private and public API endpoints:
 * success: { ok: true, data: ... }
 * failure: { ok: false, error: { code, message, fields? } }
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200): Response
    {
        return Response::json(['ok' => true, 'data' => $data], $status);
    }

    /**
     * @param array<string, string|bool|list<string>>|null $fields field-level validation errors and confirmable flags
     */
    public static function error(string $code, string $message, int $status = 400, ?array $fields = null): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null && $fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['ok' => false, 'error' => $error], $status);
    }
}
