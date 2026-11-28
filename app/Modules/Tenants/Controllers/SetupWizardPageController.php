<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * Guided setup wizard shell (update.md 2.2): location → first service →
 * who takes bookings → working hours → booking rules → go live. Everything
 * hydrates from /api/setup; each step saves through the same JSON APIs the
 * rest of the dashboard uses, so nothing here bypasses normal validation.
 */
final class SetupWizardPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext) {
            throw new HttpException(403, 'FORBIDDEN', 'You need an active workspace.');
        }

        return $this->view->response('onboarding/setup.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
