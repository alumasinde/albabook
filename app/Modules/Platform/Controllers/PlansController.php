<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Repositories\PlanRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\UuidFactory;

/**
 * Plan catalogue administration: create/edit plans and their feature maps,
 * activate/deactivate, and see which tenants subscribe. Everything here is
 * behind platform.billing.manage and audited.
 */
final class PlansController
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly PlanRepository $plans,
        private readonly UuidFactory $uuids,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->response('admin/plans.twig', [
            'plans' => $this->plans->listAllWithStats(),
            'features' => $this->plans->features(),
            'status' => $request->query('status', ''),
            'active_nav' => 'plans',
            'page_title' => 'Plans & subscriptions',
            'admin_email' => $this->auth->user?->email,
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$data, $features, $errors] = $this->validated($request, null);
        if ($errors !== []) {
            return $this->view->response('admin/plan-form.twig', $this->formData($data, $errors, null), 422);
        }

        $data['uuid'] = $this->uuids->generate();
        $planId = $this->plans->insert($data);
        $this->plans->saveFeatures($planId, $features);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLAN_CREATED,
            tenantId: null,
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'plan',
            targetId: (string) $data['uuid'],
            before: [],
            after: ['code' => $data['code'], 'name' => $data['name'], 'price_minor' => $data['price_minor']],
            request: $request,
        ));

        return Response::redirect('/plans?status=created', 303);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        $plan = $this->plans->findByUuid((string) $params['uuid']);
        if ($plan === null) {
            return Response::redirect('/plans?status=not_found', 303);
        }

        return $this->view->response('admin/plan-form.twig', $this->formData([
            'code' => $plan['code'],
            'name' => $plan['name'],
            'description' => $plan['description'] ?? '',
            'price' => number_format(((int) $plan['price_minor']) / 100, 2, '.', ''),
            'currency' => $plan['currency'],
            'billing_interval' => $plan['billing_interval'],
            'is_active' => (int) $plan['is_active'] === 1,
            'sort_position' => (string) $plan['sort_position'],
        ], [], $plan));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $plan = $this->plans->findByUuid((string) $params['uuid']);
        if ($plan === null) {
            return Response::redirect('/plans?status=not_found', 303);
        }

        $planId = (int) $plan['id'];
        [$data, $features, $errors] = $this->validated($request, $planId);
        if ($errors !== []) {
            return $this->view->response('admin/plan-form.twig', $this->formData($data, $errors, $plan), 422);
        }

        $this->plans->update($planId, $data);
        $this->plans->saveFeatures($planId, $features);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLAN_UPDATED,
            tenantId: null,
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'plan',
            targetId: (string) $plan['uuid'],
            before: ['code' => $plan['code'], 'price_minor' => (int) $plan['price_minor'], 'is_active' => (int) $plan['is_active'] === 1],
            after: ['code' => $data['code'], 'price_minor' => $data['price_minor'], 'is_active' => $data['is_active'] === 1],
            request: $request,
        ));

        return Response::redirect('/plans?status=updated', 303);
    }

    /** @param array<string, string> $params */
    public function toggle(Request $request, array $params): Response
    {
        $plan = $this->plans->findByUuid((string) $params['uuid']);
        if ($plan === null) {
            return Response::redirect('/plans?status=not_found', 303);
        }

        $this->plans->update((int) $plan['id'], [
            'code' => $plan['code'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'price_minor' => (int) $plan['price_minor'],
            'currency' => $plan['currency'],
            'billing_interval' => $plan['billing_interval'],
            'is_active' => (int) $plan['is_active'] === 1 ? 0 : 1,
            'sort_position' => (int) $plan['sort_position'],
        ]);

        return Response::redirect('/plans?status=toggled', 303);
    }

    /** Delete is only allowed before the plan has any subscription history.
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $plan = $this->plans->findByUuid((string) $params['uuid']);
        if ($plan === null) {
            return Response::redirect('/plans?status=not_found', 303);
        }
        if (!$this->plans->delete((int) $plan['id'])) {
            return Response::redirect('/plans?status=delete_blocked', 303);
        }

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLAN_DELETED,
            tenantId: null,
            actorType: 'platform_user',
            actorId: $this->auth->user?->id,
            targetType: 'plan',
            targetId: (string) $plan['uuid'],
            before: ['code' => $plan['code'], 'name' => $plan['name']],
            after: [],
            request: $request,
        ));

        return Response::redirect('/plans?status=deleted', 303);
    }

    /** @param array<string, string> $params */
    public function createForm(Request $request, array $params): Response
    {
        return $this->view->response('admin/plan-form.twig', $this->formData([
            'code' => '',
            'name' => '',
            'description' => '',
            'price' => '0.00',
            'currency' => 'KES',
            'billing_interval' => 'monthly',
            'is_active' => true,
            'sort_position' => '0',
        ], [], null));
    }

    /**
     * Validate the form body; returns [column data, feature map, field errors].
     *
     * @return array{0: array<string, mixed>, 1: array<string, array{is_enabled: bool, limit_value: ?int}>, 2: array<string, string>}
     */
    private function validated(Request $request, ?int $exceptPlanId): array
    {
        $body = $request->body();
        $errors = [];

        $code = strtolower(trim((string) ($body['code'] ?? '')));
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $errors['code'] = 'Lowercase letters, digits, dashes and underscores only.';
        } elseif ($this->plans->codeTaken($code, $exceptPlanId)) {
            $errors['code'] = 'That code is already used by another plan.';
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Give the plan a name (max 100 characters).';
        }

        $description = trim((string) ($body['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            $errors['description'] = 'Keep the description under 500 characters.';
        }

        $priceRaw = trim((string) ($body['price'] ?? ''));
        if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 100000000) {
            $errors['price'] = 'Enter a valid price (major units, e.g. 1500.00).';
        }
        $priceMinor = $errors === [] || !isset($errors['price']) ? (int) round(((float) $priceRaw) * 100) : 0;

        $currency = strtoupper(trim((string) ($body['currency'] ?? 'KES')));
        $validCurrencies = array_column($this->plans->currencies(), 'code');
        if (!in_array($currency, $validCurrencies, true)) {
            $errors['currency'] = 'Choose a supported currency.';
        }

        $interval = (string) ($body['billing_interval'] ?? 'monthly');
        if (!in_array($interval, ['monthly', 'yearly'], true)) {
            $interval = 'monthly';
        }

        $sort = trim((string) ($body['sort_position'] ?? '0'));
        $sortPosition = ctype_digit($sort) ? min(65535, (int) $sort) : 0;

        // Feature map: every known feature gets a row; limit-type features
        // need a non-negative integer limit when enabled.
        $featureMap = [];
        $featureInput = is_array($body['features'] ?? null) ? $body['features'] : [];
        foreach ($this->plans->features() as $feature) {
            $key = (string) $feature['code'];
            $enabled = isset($featureInput[$key]['enabled']);
            $limit = null;
            if ($feature['value_type'] === 'limit' && $enabled) {
                $raw = trim((string) ($featureInput[$key]['limit'] ?? ''));
                if ($raw === '') {
                    // Empty = unlimited is not the schema's meaning: NULL means
                    // "no limit value", which FeatureGate treats as enabled-but-
                    // unbounded. Keep NULL rather than inventing a cap.
                    $limit = null;
                } elseif (ctype_digit($raw)) {
                    $limit = (int) $raw;
                } else {
                    $errors['features'] = 'Limits must be whole numbers — leave empty for unlimited.';
                }
            }
            $featureMap[$key] = ['is_enabled' => $enabled, 'limit_value' => $limit];
        }

        $data = [
            'code' => $code,
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'billing_interval' => $interval,
            'is_active' => isset($body['is_active']) ? 1 : 0,
            'sort_position' => $sortPosition,
        ];

        return [$data, $featureMap, $errors];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private function formData(array $data, array $errors, ?array $plan): array
    {
        return [
            'plan' => $plan,
            'data' => $data,
            'errors' => $errors,
            'features' => $this->plans->features(),
            'featureMap' => $plan === null ? [] : $this->plans->featuresFor((int) $plan['id']),
            'subscribers' => $plan === null ? [] : $this->plans->subscribersFor((int) $plan['id']),
            'currencies' => $this->plans->currencies(),
            'active_nav' => 'plans',
            'page_title' => $plan === null ? 'New plan' : 'Edit plan · ' . $plan['name'],
            'admin_email' => $this->auth->user?->email,
        ];
    }
}
