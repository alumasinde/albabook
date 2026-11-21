<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Services\MfaService;
use App\Modules\Auth\Services\PasswordChangeService;

/**
 * Account security page: password change and two-factor management. Disabling
 * a factor is a step-up action and requires the current password (auth.md §8c).
 */
final class SecurityController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
        private readonly PasswordChangeService $passwords,
        private readonly MfaService $mfa,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, [], []);
    }

    /** @param array<string, string> $params */
    public function changePassword(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            $this->passwords->change(
                $user->id,
                $user->sessionId,
                (string) $request->input('current_password', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
                $request,
            );
        } catch (ValidationException $e) {
            return $this->page($request, $e->errors, [], 422);
        } catch (InvalidCredentialsException $e) {
            return $this->page($request, ['current_password' => $e->getMessage()], [], 422);
        }

        return Response::redirect('/security?status=password');
    }

    /** @param array<string, string> $params */
    public function disableMfa(Request $request, array $params): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        if (!$this->passwords->verifyCurrentPassword($user->id, (string) $request->input('current_password', ''))) {
            return $this->page($request, ['current_password' => 'That password is not correct.'], [], 422);
        }

        $this->mfa->disable(MfaSubject::User, $user->id, $request);

        return Response::redirect('/security?status=mfa_disabled');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $old
     */
    private function page(Request $request, array $errors, array $old, int $status = 200): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->view->response('security/index.twig', [
            'user' => $user,
            'mfaEnabled' => $this->mfa->hasConfirmedFactor(MfaSubject::User, $user->id),
            'recoveryCodes' => $this->mfa->unusedRecoveryCodes(MfaSubject::User, $user->id),
            'errors' => $errors,
            'old' => $old,
            'status' => $request->query('status', ''),
        ], $status);
    }
}
