<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Billing\Services\FeatureGate;
use App\Shared\Support\UuidFactory;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;

/**
 * Self-service tenant creation after the account is registered (plan.md §25.1
 * steps 3 and 6). The owner is provisioned with copied role templates and an
 * active membership in one transaction; the slug is checked first.
 */
final class TenantRegistrationService
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly TenantSlugService $slugs,
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly FeatureGate $features,
        private readonly UuidFactory $uuids,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function register(array $input, int $ownerUserId, Request $request): TenantContext
    {
        $data = $this->validator->validate($input, [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:3'],
        ]);

        $timezone = (string) ($data['timezone'] ?? '');
        if ($timezone === '') {
            $timezone = $this->config->string('app.default_tenant_timezone', 'Africa/Nairobi');
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency === '') {
            $currency = $this->config->string('app.default_tenant_currency', 'KES');
        }

        $errors = [];
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a valid timezone.';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['currency'] = 'Choose an ISO 4217 currency code.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $slug = $this->slugs->assertAvailable((string) $data['slug']);
        $name = (string) $data['name'];

        $result = $this->db->transaction(function () use ($name, $slug, $timezone, $currency, $ownerUserId, $request): array {
            $created = $this->provisioning->provision([
                'name' => $name,
                'slug' => $slug,
                'timezone' => $timezone,
                'currency' => $currency,
                'default_locale' => 'en',
            ], $ownerUserId, 'pending');

            // Every new business starts on the trial plan, so feature limits
            // (bookings, SMS, premium features) come from an explicit plan
            // rather than the bare defaults.
            $this->features->startTrial($created['tenant_id'], $this->uuids->generate());

            $this->audit->record(new AuditEntry(
                action: AuditAction::TENANT_REGISTERED,
                tenantId: $created['tenant_id'],
                actorType: 'tenant_user',
                actorId: $ownerUserId,
                targetType: 'tenant',
                targetId: $created['tenant_uuid'],
                after: ['name' => $name, 'slug' => $slug],
                request: $request,
            ));

            return $created;
        });

        return $this->resolver->forUser($ownerUserId, (int) $result['tenant_id']);
    }
}
