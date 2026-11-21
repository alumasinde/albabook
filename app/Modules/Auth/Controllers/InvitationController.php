<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Services\InvitationService;

/**
 * Invitation acceptance. A new user sets a password; an existing user must be
 * signed in with the invited, verified address (auth.md §5).
 */
final class InvitationController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly InvitationService $invitations,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');

        try {
            $invitation = $this->invitations->preview($token);
        } catch (InvalidTokenException $e) {
            return $this->invalid($e->getMessage());
        }

        return $this->view->response('auth/invitation.twig', $this->data($token, $invitation, [], []));
    }

    /** @param array<string, string> $params */
    public function accept(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $user = $this->auth->user;

        try {
            if ($user !== null) {
                $this->invitations->acceptAsExistingUser($token, $user, $request);

                return Response::redirect('/dashboard');
            }

            $this->invitations->acceptAsNewUser($token, $request->body(), $request);

            return Response::redirect('/login?status=invited');
        } catch (ValidationException $e) {
            try {
                $invitation = $this->invitations->preview($token);
            } catch (InvalidTokenException $invalid) {
                return $this->invalid($invalid->getMessage());
            }

            return $this->view->response('auth/invitation.twig', $this->data($token, $invitation, $e->errors, $e->old), 422);
        } catch (InvalidTokenException $e) {
            return $this->invalid($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed>  $invitation
     * @param array<string, string> $errors
     * @param array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function data(string $token, array $invitation, array $errors, array $old): array
    {
        return [
            'token' => $token,
            'invitation' => $invitation,
            'mode' => $this->auth->user === null ? 'new' : 'existing',
            'signedInEmail' => $this->auth->user?->email,
            'errors' => $errors,
            'old' => $old,
        ];
    }

    private function invalid(string $message): Response
    {
        return $this->view->response('auth/invitation.twig', [
            'token' => '',
            'invitation' => null,
            'mode' => 'invalid',
            'signedInEmail' => null,
            'errors' => ['invitation' => $message],
            'old' => [],
        ], 422);
    }
}
