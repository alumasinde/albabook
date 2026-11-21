<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Context\PlatformUser;
use App\Modules\Platform\Repositories\PlatformUserRepository;
use App\Modules\Platform\Services\PlatformSessionService;

/**
 * Resolves the platform administrator session on the admin host. It never
 * blocks a request; PlatformRequireAuthMiddleware does that so the admin login
 * page can still see who is signed in.
 */
final class PlatformAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PlatformAuthContext $auth,
        private readonly PlatformSessionService $sessions,
        private readonly PlatformUserRepository $users,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $this->auth->reset();
        $session = null;

        if ($request->hostGroup() === HostGroup::Admin) {
            $session = $this->sessions->resolve($request);

            if ($session !== null) {
                $this->auth->user = new PlatformUser(
                    id: $session->userId,
                    uuid: $session->userUuid,
                    email: $session->email,
                    firstName: $session->firstName,
                    lastName: $session->lastName,
                    sessionId: $session->sessionId,
                    sessionToken: $session->token,
                    mfaVerified: $session->mfaVerified,
                    mfaEnrolled: $session->mfaEnrolled,
                );
                $this->auth->permissions = $this->users->permissionIdentifiers($session->userId);
                $this->auth->mfaPending = !$session->mfaVerified;
            }
        }

        return $next($request->withAttribute('platform_session', $session));
    }
}
