<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Settings\Repositories\SettingsRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Security\Encrypter;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for tenant settings (session-authenticated, D-008).
 * Reads require settings.view; mutations require settings.update.
 */
final class SettingsApiController
{
    private const DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd M Y'];
    private const DASHBOARD_VIEWS = ['overview', 'calendar', 'appointments'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly UuidFactory $uuids,
        private readonly Encrypter $encrypter,
        private readonly FeatureGate $features,
    ) {}

    /** Workspace profile + display preferences + dropdown metadata.
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view settings.', 403);
        }

        return ApiResponse::ok([
            'profile' => $this->settings->profile($tenant->tenantId),
            'preferences' => $this->settings->preferences($tenant->tenantId),
            'branding' => $this->settings->branding($tenant->tenantId),
            'locales' => $this->settings->locales(),
            'date_formats' => self::DATE_FORMATS,
            'dashboard_views' => self::DASHBOARD_VIEWS,
        ]);
    }

    /** Update the workspace profile + display preferences.
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fields['name'] = 'Workspace name is required (max 150 characters).';
        }

        $timezone = (string) ($body['timezone'] ?? '');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $fields['timezone'] = 'Choose a valid timezone.';
        }

        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $fields['currency'] = 'Use a 3-letter currency code, e.g. KES.';
        }

        $locale = (string) ($body['default_locale'] ?? '');
        $validLocales = array_column($this->settings->locales(), 'code');
        if (!in_array($locale, $validLocales, true)) {
            $fields['default_locale'] = 'Choose a valid language.';
        }

        $kraPin = trim((string) ($body['kra_pin'] ?? ''));
        if (mb_strlen($kraPin) > 20) {
            $fields['kra_pin'] = 'KRA PIN is too long (max 20 characters).';
        }

        $taxRate = (int) ($body['tax_rate_bp'] ?? 0);
        if ($taxRate < 0 || $taxRate > 10000) {
            $fields['tax_rate_bp'] = 'Tax rate must be between 0% and 100%.';
        }

        $dateFormat = (string) ($body['date_format'] ?? '');
        if (!in_array($dateFormat, self::DATE_FORMATS, true)) {
            $fields['date_format'] = 'Choose a valid date format.';
        }

        $dashboardView = (string) ($body['default_dashboard_view'] ?? '');
        if (!in_array($dashboardView, self::DASHBOARD_VIEWS, true)) {
            $fields['default_dashboard_view'] = 'Choose a valid default view.';
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->settings->updateProfile($tenant->tenantId, [
            'name' => $name,
            'timezone' => $timezone,
            'currency' => $currency,
            'default_locale' => $locale,
            'vat_registered' => !empty($body['vat_registered']) ? 1 : 0,
            'tax_rate_bp' => $taxRate,
            'kra_pin' => $kraPin !== '' ? $kraPin : null,
        ]);
        $this->settings->updatePreferences($tenant->tenantId, [
            'date_format' => $dateFormat,
            'default_dashboard_view' => $dashboardView,
        ]);

        return ApiResponse::ok([
            'profile' => $this->settings->profile($tenant->tenantId),
            'preferences' => $this->settings->preferences($tenant->tenantId),
        ]);
    }

    /** Update the public booking page theme (tenant_branding).
     * @param array<string, string> $params
     */
    public function updateBranding(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }
        if (!$this->features->enabled($tenant->tenantId, 'advanced_themes')) {
            return ApiResponse::error('FEATURE_NOT_AVAILABLE', 'Custom themes are not included in your plan.', 403);
        }

        $body = $request->body();
        $fields = [];
        $branding = [];

        foreach (['primary_color', 'secondary_color', 'accent_color', 'background_color', 'text_color'] as $key) {
            $value = strtoupper(trim((string) ($body[$key] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $value) !== 1) {
                $fields[$key] = 'Use a 6-digit hex colour, e.g. #6D5CE8.';
            } else {
                $branding[$key] = $value;
            }
        }
        $appearance = (string) ($body['appearance'] ?? 'light');
        if (!in_array($appearance, ['light', 'dark'], true)) {
            $fields['appearance'] = 'Appearance must be light or dark.';
        } else {
            $branding['appearance'] = $appearance;
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->settings->upsertBranding($tenant->tenantId, $branding);

        return ApiResponse::ok(['branding' => $this->settings->branding($tenant->tenantId)]);
    }

    /* -------------------------------------------------------- notifications */

    /** Channel×event preference grid + provider accounts.
     * @param array<string, string> $params
     */
    public function notifications(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view settings.', 403);
        }

        return ApiResponse::ok([
            'events' => $this->settings->notificationEvents(),
            'channels' => $this->settings->notificationChannels(),
            'prefs' => $this->settings->notificationPrefs($tenant->tenantId),
            'providers' => $this->settings->notificationProviders(),
            'accounts' => array_map(
                static fn(array $a): array => [
                    'id' => (int) $a['id'],
                    'provider_id' => (int) $a['provider_id'],
                    'provider_code' => $a['provider_code'],
                    'provider_name' => $a['provider_name'],
                    'channel' => $a['channel'],
                    'sender_id' => $a['sender_id'],
                    'status' => $a['status'],
                    'has_credentials' => (bool) $a['has_credentials'],
                ],
                $this->settings->notificationAccounts($tenant->tenantId),
            ),
        ]);
    }

    /** Toggle a channel×event preference.
     * @param array<string, string> $params
     */
    public function updateNotificationPref(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        $channel = (string) ($body['channel'] ?? '');
        $eventKey = (string) ($body['event_key'] ?? '');

        $validChannels = array_column($this->settings->notificationChannels(), 'code');
        $validEvents = array_column($this->settings->notificationEvents(), 'code');
        if (!in_array($channel, $validChannels, true) || !in_array($eventKey, $validEvents, true)) {
            return ApiResponse::error('VALIDATION', 'Invalid channel or event.', 422);
        }

        $enabled = !empty($body['is_enabled']);
        $this->settings->upsertNotificationPref($tenant->tenantId, $channel, $eventKey, $enabled);

        return ApiResponse::ok(['saved' => true]);
    }

    /** Connect an SMS/WhatsApp provider account.
     * @param array<string, string> $params
     */
    public function createNotificationAccount(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        $fields = [];

        $providerId = (int) ($body['provider_id'] ?? 0);
        if ($providerId <= 0 || !$this->settings->notificationProviderValid($providerId)) {
            $fields['provider_id'] = 'Choose a provider.';
        }

        $senderId = trim((string) ($body['sender_id'] ?? ''));
        if (mb_strlen($senderId) > 64) {
            $fields['sender_id'] = 'Sender ID is too long (max 64 characters).';
        } elseif ($senderId !== '' && !$this->features->enabled($tenant->tenantId, 'sms_custom_sender_id')) {
            $fields['sender_id'] = 'A custom sender ID is not included in your plan.';
        }

        $credentials = trim((string) ($body['credentials'] ?? ''));
        if ($credentials === '') {
            $fields['credentials'] = 'Paste the API key or JSON credentials from your provider.';
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        try {
            $this->settings->insertNotificationAccount($tenant->tenantId, [
                'provider_id' => $providerId,
                'sender_id' => $senderId !== '' ? $senderId : null,
                'credentials_encrypted' => $this->encrypter->encrypt($credentials),
                'status' => 'active',
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_tenant_notification_accounts_provider')) {
                return ApiResponse::error('ACCOUNT_EXISTS', 'An account for this provider already exists.', 409);
            }
            throw $e;
        }

        return ApiResponse::ok(['saved' => true], 201);
    }

    /** Enable/disable a notification account or rotate its sender ID.
     * @param array<string, string> $params
     */
    public function updateNotificationAccount(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $account = $this->settings->findNotificationAccount($tenant->tenantId, (int) $params['id']);
        if ($account === null) {
            return ApiResponse::error('NOT_FOUND', 'Account not found.', 404);
        }

        $body = $request->body();
        $status = (string) ($body['status'] ?? $account['status']);
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'disabled';
        }
        $senderId = array_key_exists('sender_id', $body)
            ? (trim((string) $body['sender_id']) ?: null)
            : $account['sender_id'];
        if ($senderId !== null
            && $senderId !== ''
            && $senderId !== $account['sender_id']
            && !$this->features->enabled($tenant->tenantId, 'sms_custom_sender_id')) {
            return ApiResponse::error('FEATURE_NOT_AVAILABLE', 'A custom sender ID is not included in your plan.', 403);
        }

        $this->settings->updateNotificationAccount($tenant->tenantId, (int) $account['id'], $status, $senderId);

        return ApiResponse::ok(['saved' => true]);
    }

    /* ------------------------------------------------------------- payments */

    /** Payment providers, M-Pesa accounts and recent payments.
     * @param array<string, string> $params
     */
    public function payments(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view settings.', 403);
        }

        return ApiResponse::ok([
            'providers' => $this->settings->paymentProviders(),
            'accounts' => array_map(
                static fn(array $a): array => [
                    'uuid' => $a['uuid'],
                    'provider' => $a['provider'],
                    'account_type' => $a['account_type'],
                    'shortcode' => $a['shortcode'],
                    'display_name' => $a['display_name'],
                    'branch_id' => $a['branch_id'] !== null ? (int) $a['branch_id'] : null,
                    'branch_name' => $a['branch_name'],
                    'status' => $a['status'],
                    'has_credentials' => (bool) $a['has_credentials'],
                ],
                $this->settings->paymentAccounts($tenant->tenantId),
            ),
            'branches' => $this->settings->branches($tenant->tenantId),
            'checkout_full_payment_required' => $this->settings->preferences($tenant->tenantId)['checkout_full_payment_required'],
            'service_charge_bp' => (int) ($this->settings->jsonPreferences($tenant->tenantId)['service_charge_bp'] ?? 0),
            'receipt_footer_note' => (string) ($this->settings->jsonPreferences($tenant->tenantId)['receipt_footer_note'] ?? ''),
            'tax_rate_bp' => (int) $this->settings->profile($tenant->tenantId)['tax_rate_bp'],
            'recent_payments' => $membership->can('payments.view')
                ? $this->settings->recentPayments($tenant->tenantId)
                : [],
        ]);
    }

    /** Checkout payment policy + tax & charge settings for the till.
     * @param array<string, string> $params
     */
    public function updatePaymentPolicy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        $fields = [];

        $charge = $body['service_charge_bp'] ?? 0;
        if (!is_numeric($charge) || (int) $charge < 0 || (int) $charge > 5000) {
            $fields['service_charge_bp'] = 'Service charge must be between 0% and 50%.';
        }
        $tax = $body['tax_rate_bp'] ?? null;
        if ($tax !== null && (!is_numeric($tax) || (int) $tax < 0 || (int) $tax > 10000)) {
            $fields['tax_rate_bp'] = 'VAT rate must be between 0% and 100%.';
        }
        $note = trim((string) ($body['receipt_footer_note'] ?? ''));
        if (mb_strlen($note) > 200) {
            $fields['receipt_footer_note'] = 'Receipt note is too long (max 200 characters).';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $required = !empty($body['checkout_full_payment_required']);
        $this->settings->updatePreferences($tenant->tenantId, [
            'checkout_full_payment_required' => $required ? 1 : 0,
        ]);
        $this->settings->updateJsonPreferences($tenant->tenantId, 'service_charge_bp', (int) $charge);
        $this->settings->updateJsonPreferences($tenant->tenantId, 'receipt_footer_note', $note !== '' ? $note : null);
        if ($tax !== null) {
            $this->settings->updateProfile($tenant->tenantId, ['tax_rate_bp' => (int) $tax]);
        }

        $prefs = $this->settings->jsonPreferences($tenant->tenantId);

        return ApiResponse::ok([
            'checkout_full_payment_required' => $required,
            'service_charge_bp' => (int) ($prefs['service_charge_bp'] ?? 0),
            'receipt_footer_note' => (string) ($prefs['receipt_footer_note'] ?? ''),
            'tax_rate_bp' => (int) $this->settings->profile($tenant->tenantId)['tax_rate_bp'],
        ]);
    }

    /** Add an M-Pesa till/paybill account.
     * @param array<string, string> $params
     */
    public function createPaymentAccount(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $body = $request->body();
        $fields = [];

        $provider = (string) ($body['provider'] ?? '');
        if (!$this->settings->paymentProviderValid($provider)) {
            $fields['provider'] = 'Choose a payment provider.';
        }

        $accountType = (string) ($body['account_type'] ?? '');
        if (!in_array($accountType, ['till', 'paybill'], true)) {
            $fields['account_type'] = 'Choose till or paybill.';
        }

        $shortcode = trim((string) ($body['shortcode'] ?? ''));
        if ($shortcode === '' || mb_strlen($shortcode) > 20 || preg_match('/^\d+$/', $shortcode) !== 1) {
            $fields['shortcode'] = 'Enter the numeric till or paybill shortcode.';
        }

        $displayName = trim((string) ($body['display_name'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            $fields['display_name'] = 'A label is required (max 120 characters).';
        }

        $branchId = (int) ($body['branch_id'] ?? 0);
        if ($branchId > 0 && !$this->settings->branchValid($tenant->tenantId, $branchId)) {
            $fields['branch_id'] = 'Choose a valid branch.';
        }
        // Branch-limited members may only create accounts for their own
        // branches; a NULL branch account is tenant-wide.
        if ($fields === [] && $membership->branchIds !== null) {
            if ($branchId === 0 || !in_array($branchId, $membership->branchIds, true)) {
                $fields['branch_id'] = 'You do not have access to that branch.';
            }
        }

        $credentials = trim((string) ($body['credentials'] ?? ''));
        if ($credentials === '') {
            $fields['credentials'] = 'Paste the Daraja consumer key/secret JSON.';
        }

        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->settings->insertPaymentAccount($tenant->tenantId, [
            'uuid' => $this->uuids->generate(),
            'branch_id' => $branchId > 0 ? $branchId : null,
            'provider' => $provider,
            'account_type' => $accountType,
            'shortcode' => $shortcode,
            'display_name' => $displayName,
            'credentials_encrypted' => $this->encrypter->encrypt($credentials),
            'status' => 'pending_golive',
        ]);

        return ApiResponse::ok(['saved' => true], 201);
    }

    /** Enable/disable a payment account.
     * @param array<string, string> $params
     */
    public function updatePaymentAccount(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $account = $this->settings->findPaymentAccount($tenant->tenantId, (string) $params['uuid']);
        if ($account === null) {
            return ApiResponse::error('NOT_FOUND', 'Account not found.', 404);
        }
        $accountBranch = $account['branch_id'] === null ? null : (int) $account['branch_id'];
        if ($membership->branchIds !== null
            && ($accountBranch === null || !in_array($accountBranch, $membership->branchIds, true))) {
            return ApiResponse::error('FORBIDDEN', 'You do not have access to this branch.', 403);
        }

        $status = (string) ($request->body()['status'] ?? $account['status']);
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'disabled';
        }

        $this->settings->updatePaymentAccountStatus($tenant->tenantId, (int) $account['id'], $status);

        return ApiResponse::ok(['saved' => true]);
    }



    /** @param array<string, string> $params */
    public function categories(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view settings.', 403);
        }

        return ApiResponse::ok([
            'categories' => array_map(
                fn(array $row): array => $this->category($row),
                $this->settings->categories($tenant->tenantId),
            ),
        ]);
    }

    /** @param array<string, string> $params */
    public function createCategory(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        [$data, $fields] = $this->validatedCategory($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $uuid = $this->uuids->generate();
        $this->settings->insertCategory($data + [
            'uuid' => $uuid,
            'tenant_id' => $tenant->tenantId,
        ]);

        return ApiResponse::ok(['categories' => array_map(
            fn(array $row): array => $this->category($row),
            $this->settings->categories($tenant->tenantId),
        )], 201);
    }

    /** @param array<string, string> $params */
    public function updateCategory(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $category = $this->settings->findCategoryByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($category === null) {
            return ApiResponse::error('NOT_FOUND', 'Category not found.', 404);
        }

        [$data, $fields] = $this->validatedCategory($request->body());
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->settings->updateCategory($tenant->tenantId, (int) $category['id'], $data);

        return ApiResponse::ok(['categories' => array_map(
            fn(array $row): array => $this->category($row),
            $this->settings->categories($tenant->tenantId),
        )]);
    }

    /** @param array<string, string> $params */
    public function deleteCategory(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('settings.update')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to update settings.', 403);
        }

        $category = $this->settings->findCategoryByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($category === null) {
            return ApiResponse::error('NOT_FOUND', 'Category not found.', 404);
        }

        if ($this->settings->categoryReferenceCount($tenant->tenantId, (int) $category['id']) > 0) {
            return ApiResponse::error(
                'CATEGORY_IN_USE',
                'Services still use this category. Reassign them or deactivate the category instead.',
                409,
            );
        }

        $this->settings->deleteCategory($tenant->tenantId, (int) $category['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validatedCategory(array $body): array
    {
        $fields = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $fields['name'] = 'Category name is required (max 120 characters).';
        }

        $description = trim((string) ($body['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            $fields['description'] = 'Description is too long (max 500 characters).';
        }

        $data = [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'sort_position' => max(0, (int) ($body['sort_position'] ?? 0)),
            'is_active' => !empty($body['is_active']) ? 1 : 0,
        ];

        return [$data, $fields];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function category(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'description' => $row['description'],
            'sort_position' => (int) $row['sort_position'],
            'is_active' => (bool) $row['is_active'],
            'services' => (int) ($row['service_count'] ?? 0),
        ];
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new \LogicException('Tenant context required.');
        }

        return [$tenant, $membership];
    }
}
