<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Platform\Dto\ResolvedPlatformSession;
use App\Modules\Platform\Services\PlatformAuthenticationService;
use App\Shared\Security\AuthCookieFactory;

final class LogoutController
{
    public function __construct(
        private readonly PlatformAuthenticationService $authentication,
        private readonly AuthCookieFactory $cookies,
    ) {}

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $session = $request->attribute('platform_session');
        if ($session instanceof ResolvedPlatformSession) {
            $this->authentication->logout($session, $request);
        }

        return Response::redirect('/login')
            ->withCookie($this->cookies->clearPlatformSession($request));
    }
}
