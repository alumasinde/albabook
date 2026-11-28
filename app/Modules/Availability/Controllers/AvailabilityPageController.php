<?php

declare(strict_types=1);

namespace App\Modules\Availability\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * "Time off & closures" shell. Any member with a schedule-related
 * permission (or a staff profile of their own) may open it; the API
 * decides what each tab lets them change.
 */
final class AvailabilityPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext) {
            throw new HttpException(403, 'FORBIDDEN', 'You need an active workspace.');
        }

        return $this->view->response('availability/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
