<?php

declare(strict_types=1);

namespace App\Modules\System\Controllers;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Shared\Money\Money;

/**
 * /_system/components — every shared component on the shell of the current
 * host, for design review before Phase 2. Not available in production.
 * The form posts back through CSRF and server-side validation so the error
 * states can be seen with real behaviour.
 */
final class ComponentCatalogueController
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->guard();

        return $this->render($request, [], ['full_name' => '', 'email' => '', 'notes' => '']);
    }

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        $this->guard();

        $old = [
            'full_name' => trim((string) $request->input('full_name', '')),
            'email' => trim((string) $request->input('email', '')),
            'notes' => trim((string) $request->input('notes', '')),
        ];
        $errors = [];
        if ($old['full_name'] === '') {
            $errors['full_name'] = 'Enter your full name.';
        }
        if (filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter an email address like name@example.com.';
        }
        if (mb_strlen($old['notes']) > 500) {
            $errors['notes'] = 'Keep notes under 500 characters.';
        }

        return $this->render($request, $errors, $old, $errors === [] ? 'Form submitted. CSRF and validation passed.' : null, $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Request $request, array $errors, array $old, ?string $success = null, int $status = 200): Response
    {
        $shell = match ($request->hostGroup()) {
            HostGroup::Booking => 'layouts/public.twig',
            HostGroup::Admin => 'layouts/admin.twig',
            default => 'layouts/dashboard.twig',
        };

        return $this->view->response('pages/system/components.twig', [
            'shell' => $shell,
            'errors' => $errors,
            'old' => $old,
            'success' => $success,
            'sample_amount' => new Money(3850000, 'KES'),
            'sample_rows' => [
                ['time' => '09:00', 'customer' => 'Jane W.', 'service' => 'Hair styling', 'staff' => 'Sarah', 'status' => 'confirmed'],
                ['time' => '10:30', 'customer' => 'Mary K.', 'service' => 'Manicure', 'staff' => 'Grace', 'status' => 'pending'],
                ['time' => '12:00', 'customer' => 'Achieng O.', 'service' => 'Massage (60 min)', 'staff' => 'Peter', 'status' => 'completed'],
                ['time' => '14:15', 'customer' => 'Wanjiru N.', 'service' => 'Braids', 'staff' => 'Sarah', 'status' => 'no_show'],
            ],
        ], $status);
    }

    private function guard(): void
    {
        if ($this->config->isProduction()) {
            throw HttpException::notFound();
        }
    }
}
