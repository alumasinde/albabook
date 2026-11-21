<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Repositories\UserRepository;
use App\Modules\Auth\Services\EmailVerificationService;

final class EmailVerificationController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly EmailVerificationService $verification,
        private readonly UserRepository $users,
    ) {}

    /** @param array<string, string> $params */
    public function pending(Request $request, array $params): Response
    {
        // The emailed link is /verify-email?token=...; the same page doubles as
        // the "check your inbox" screen for signed-in, unverified users.
        if ((string) $request->query('token', '') !== '') {
            return $this->verify($request);
        }

        return $this->view->response('auth/verify-email.twig', [
            'verified' => false,
            'error' => null,
            'resent' => $request->query('status') === 'resent',
            'canResend' => $this->auth->user !== null,
        ]);
    }

    private function verify(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        try {
            $this->verification->verify($token, $request);
        } catch (InvalidTokenException $e) {
            return $this->view->response('auth/verify-email.twig', [
                'verified' => false,
                'error' => $e->getMessage(),
                'resent' => false,
                'canResend' => $this->auth->user !== null,
            ], 422);
        }

        return $this->view->response('auth/verify-email.twig', [
            'verified' => true,
            'error' => null,
            'resent' => false,
            'canResend' => false,
        ]);
    }

    /** @param array<string, string> $params */
    public function resend(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        $row = $this->users->findById($user->id);
        if ($row === null || $row['email_verified_at'] !== null) {
            return Response::redirect('/dashboard');
        }

        try {
            $this->verification->resend($row, $request);
        } catch (TooManyAttemptsException) {
            return $this->view->response('auth/verify-email.twig', [
                'verified' => false,
                'error' => 'Too many requests. Try again in a little while.',
                'resent' => false,
                'canResend' => true,
            ], 429);
        }

        return Response::redirect('/verify-email?status=resent');
    }
}
