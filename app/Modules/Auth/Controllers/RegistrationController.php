<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Auth\Services\RegistrationService;

/**
 * Creates the account only. Duplicate emails and fresh sign-ups see the same
 * response, so the page cannot be used to enumerate accounts (auth.md §8b).
 */
final class RegistrationController
{
    public function __construct(
        private readonly View $view,
        private readonly RegistrationService $registration,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->view->response('auth/register.twig', [
            'old' => [],
            'errors' => [],
            'sent' => $request->query('status') === 'sent',
        ]);
    }

    /** @param array<string, string> $params */
    public function register(Request $request, array $params): Response
    {
        try {
            $this->registration->register($request->body(), $request);
        } catch (ValidationException $e) {
            return $this->view->response('auth/register.twig', [
                'old' => $e->old,
                'errors' => $e->errors,
                'sent' => false,
            ], 422);
        }

        return Response::redirect('/register?status=sent');
    }
}
