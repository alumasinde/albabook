<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Marketing\Repositories\MarketingRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppUrl;
use App\Shared\Money\Money;
use App\Shared\Security\RateLimiter;
use App\Shared\Support\UuidFactory;

/**
 * Public marketing site on the bare domain (albabook.co.ke). Server-rendered
 * Twig; spa.js upgrades in-site links to fragment swaps. Pricing is read live
 * from the plans tables, demo requests are stored in demo_requests.
 */
final class MarketingController
{
    public const BUSINESS_TYPES = [
        'salon' => 'Hair & beauty salon',
        'barber' => 'Barbershop',
        'spa' => 'Spa & wellness',
        'clinic' => 'Clinic or therapy practice',
        'fitness' => 'Fitness studio',
        'other' => 'Something else',
    ];

    public const BRANCH_COUNTS = ['1' => 'One location', '2-5' => '2–5 locations', '6+' => '6 or more'];

    private const DEMO_LIMIT = 5;
    private const DEMO_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly View $view,
        private readonly MarketingRepository $marketing,
        private readonly AppUrl $urls,
        private readonly RateLimiter $limiter,
        private readonly UuidFactory $uuids,
    ) {}

    /** @param array<string, string> $params */
    public function home(Request $request, array $params): Response
    {
        return $this->page($request, 'marketing/home.twig', 'home', [
            'plans' => $this->plans(),
        ]);
    }

    /** @param array<string, string> $params */
    public function features(Request $request, array $params): Response
    {
        return $this->page($request, 'marketing/features.twig', 'features');
    }

    /** @param array<string, string> $params */
    public function pricing(Request $request, array $params): Response
    {
        $plans = $this->plans();

        // Comparison matrix: every feature any plan includes, one cell per
        // plan — true (included), a limit string, or null (not included).
        $rows = [];
        foreach ($plans as $i => $plan) {
            foreach ($plan['feature_lines'] as $line) {
                $rows[$line['label']] ??= array_fill(0, count($plans), null);
                $rows[$line['label']][$i] = $line['value'] ?? true;
            }
        }

        return $this->page($request, 'marketing/pricing.twig', 'pricing', [
            'plans' => $plans,
            'comparison' => $rows,
        ]);
    }

    /** @param array<string, string> $params */
    public function contact(Request $request, array $params): Response
    {
        return $this->page($request, 'marketing/contact.twig', 'contact', [
            'sent' => $request->query('sent', '') === '1',
        ]);
    }

    /** @param array<string, string> $params */
    public function submitContact(Request $request, array $params): Response
    {
        $input = static fn(string $key, int $max): string => mb_substr(trim((string) $request->input($key, '')), 0, $max);

        $old = [
            'full_name' => $input('full_name', 150),
            'business_name' => $input('business_name', 150),
            'email' => strtolower($input('email', 254)),
            'phone' => $input('phone', 32),
            'business_type' => $input('business_type', 40),
            'branch_count' => $input('branch_count', 10),
            'message' => $input('message', 2000),
        ];

        // Honeypot: real visitors never see or fill this field. Pretend
        // success so bots learn nothing.
        if ($input('website', 200) !== '') {
            return $this->contactDone($request);
        }

        $errors = [];
        if ($old['full_name'] === '') {
            $errors['full_name'] = 'Enter your name.';
        }
        if ($old['business_name'] === '') {
            $errors['business_name'] = 'Enter your business name.';
        }
        if (filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($old['phone'] !== '' && preg_match('/^\+?[0-9 ()-]{7,20}$/', $old['phone']) !== 1) {
            $errors['phone'] = 'Enter a valid phone number, e.g. +254 712 345 678.';
        }
        if (!array_key_exists($old['business_type'], self::BUSINESS_TYPES)) {
            $errors['business_type'] = 'Choose your type of business.';
        }
        if (!array_key_exists($old['branch_count'], self::BRANCH_COUNTS)) {
            $errors['branch_count'] = 'Choose how many locations you have.';
        }

        if ($errors !== []) {
            return $request->wantsJson()
                ? ApiResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', 422, $errors)
                : $this->page($request, 'marketing/contact.twig', 'contact', ['errors' => $errors, 'old' => $old], 422);
        }

        $ipHash = hash('sha256', $request->clientIp() ?: 'unknown');
        $limit = $this->limiter->hit('demo:ip:' . $ipHash, self::DEMO_LIMIT, self::DEMO_WINDOW_SECONDS);
        if (!$limit->allowed) {
            if ($request->wantsJson()) {
                return ApiResponse::error('TOO_MANY_REQUESTS', 'Too many requests. Please try again later.', 429);
            }
            throw new HttpException(429, 'TOO_MANY_REQUESTS', 'Too many requests. Please try again later.');
        }

        $this->marketing->insertDemoRequest([
            'uuid' => $this->uuids->generate(),
            'full_name' => $old['full_name'],
            'business_name' => $old['business_name'],
            'email' => $old['email'],
            'phone' => $old['phone'] !== '' ? $old['phone'] : null,
            'business_type' => $old['business_type'],
            'branch_count' => $old['branch_count'],
            'message' => $old['message'] !== '' ? $old['message'] : null,
            'ip_hash' => $ipHash,
            'user_agent' => mb_substr((string) $request->header('user-agent', ''), 0, 255),
        ]);

        return $this->contactDone($request);
    }

    private function contactDone(Request $request): Response
    {
        return $request->wantsJson()
            ? ApiResponse::ok(['sent' => true], 201)
            : Response::redirect('/contact?sent=1', 303);
    }

    /**
     * Plans with a preformatted price and readable feature lines.
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return array_map(static function (array $plan): array {
            $plan['price'] = new Money($plan['price_minor'], $plan['currency']);
            $plan['is_free'] = $plan['price_minor'] === 0;
            // {label, value}: "Staff members" / "Up to 5" reads cleanly for
            // every unit; a limit of 0 means the feature is not included.
            $lines = [];
            foreach ($plan['features'] as $f) {
                if ($f['value_type'] !== 'limit') {
                    $lines[] = ['label' => $f['name'], 'value' => null];
                } elseif ($f['limit_value'] === null) {
                    $lines[] = ['label' => $f['name'], 'value' => 'Unlimited'];
                } elseif ($f['limit_value'] > 0) {
                    $lines[] = ['label' => $f['name'], 'value' => 'Up to ' . number_format($f['limit_value'])];
                }
            }
            $plan['feature_lines'] = $lines;

            return $plan;
        }, $this->marketing->publicPlans());
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, string $template, string $nav, array $data = [], int $status = 200): Response
    {
        return $this->view->response($template, $data + [
            'active_nav' => $nav,
            'signup_url' => $this->urls->app('/register', $request),
            'login_url' => $this->urls->app('/login', $request),
            'business_types' => self::BUSINESS_TYPES,
            'branch_counts' => self::BRANCH_COUNTS,
            'errors' => [],
            'old' => [],
        ], $status);
    }
}
