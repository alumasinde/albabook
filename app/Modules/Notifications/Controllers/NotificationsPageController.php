<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/** Renders the notifications-center shell; data hydrates via the JSON API. */
final class NotificationsPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('notifications.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view notifications.');
        }

        return $this->view->response('notifications/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
