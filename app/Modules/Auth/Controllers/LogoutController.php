<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Services\AuthenticationService;
use App\Shared\Security\AuthCookieFactory;

final class LogoutController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $session = $request->attribute('auth_session');
        if ($session instanceof ResolvedSession) {
            $this->authentication->logout($session, $request);
        }

        return Response::redirect('/login')
            ->withCookie($this->cookies->clearUserSession($request))
            ->withCookie($this->cookies->clearRemember($request));
    }
}
