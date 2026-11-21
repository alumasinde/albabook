<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\View\View;
use Psr\Log\LoggerInterface;

/**
 * Turns any Throwable into a safe response.
 *
 * - Users see a stable error code, a public message and the request id.
 * - Internal details (class, file, trace, SQL) appear only when APP_DEBUG is
 *   on outside production.
 * - Every 5xx is logged with the exception; the request id ties the page the
 *   user saw to the log line.
 */
final class ExceptionHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestContext $context,
        private readonly View $view,
        private readonly bool $debug,
    ) {
    }

    public function render(\Throwable $e, Request $request): Response
    {
        $http = $e instanceof HttpException ? $e : null;
        $status = $http->status ?? 500;
        $code = $http->errorCode ?? 'SERVER_ERROR';
        $message = $http?->getMessage() ?? 'Something went wrong on our side. Please try again.';
        $requestId = $this->context->requestId ?? '';

        $this->report($e, $status, $code, $request);

        $response = $request->wantsJson()
            ? Response::json($this->jsonBody($e, $code, $message, $requestId), $status)
            : $this->htmlResponse($e, $status, $code, $message, $requestId);

        foreach ($http->headers ?? [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function report(\Throwable $e, int $status, string $code, Request $request): void
    {
        $context = ['status' => $status, 'code' => $code, 'method' => $request->method(), 'path' => $request->path()];

        if ($status >= 500) {
            $this->logger->error($e->getMessage() !== '' ? $e->getMessage() : $e::class, $context + ['exception' => $e]);
        } elseif ($status === 419) {
            $this->logger->warning('CSRF token mismatch', $context);
        } else {
            $this->logger->info('HTTP ' . $status, $context);
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(\Throwable $e, string $code, string $message, string $requestId): array
    {
        $error = ['code' => $code, 'message' => $message, 'request_id' => $requestId];
        if ($this->debug) {
            $error['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ];
        }

        return ['error' => $error];
    }

    private function htmlResponse(\Throwable $e, int $status, string $code, string $message, string $requestId): Response
    {
        try {
            $html = $this->view->render('errors/error.twig', [
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
                'debug' => $this->debug ? [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'location' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ] : null,
            ]);
        } catch (\Throwable $viewError) {
            $this->logger->error('Error page failed to render', ['exception' => $viewError]);
            $html = sprintf(
                '<!doctype html><meta charset="utf-8"><title>Error %d</title><h1>%s</h1><p>Reference: %s</p>',
                $status,
                htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return Response::html($html, $status);
    }
}
