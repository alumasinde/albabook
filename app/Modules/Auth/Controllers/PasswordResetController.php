<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Services\PasswordResetService;

/**
 * Forgot/reset password. The request page always answers the same, whether or
 * not the address exists (auth.md §4, §8b).
 */
final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly PasswordResetService $passwords,
    ) {}

    /** @param array<string, string> $params */
    public function showRequest(Request $request, array $params): Response
    {
        return $this->view->response('auth/forgot-password.twig', [
            'email' => '',
            'message' => null,
            'sent' => false,
        ]);
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params): Response
    {
        $email = (string) $request->input('email', '');
        $this->passwords->request($email, $request);

        return $this->view->response('auth/forgot-password.twig', [
            'email' => $email,
            'message' => $this->passwords->genericMessage(),
            'sent' => true,
        ]);
    }

    /** @param array<string, string> $params */
    public function showReset(Request $request, array $params): Response
    {
        return $this->view->response('auth/reset-password.twig', [
            'token' => (string) ($params['token'] ?? ''),
            'errors' => [],
        ]);
    }

    /** @param array<string, string> $params */
    public function reset(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');

        try {
            $this->passwords->reset(
                $token,
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
                $request,
            );
        } catch (ValidationException $e) {
            return $this->view->response('auth/reset-password.twig', [
                'token' => $token,
                'errors' => $e->errors,
            ], 422);
        } catch (InvalidTokenException) {
            return $this->view->response('auth/reset-password.twig', [
                'token' => '',
                'errors' => ['token' => 'This reset link is invalid or has expired. Request a new one.'],
            ], 422);
        }

        return Response::redirect('/login?status=reset');
    }
}
