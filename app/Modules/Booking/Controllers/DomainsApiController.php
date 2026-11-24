<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validation\ValidationException;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDomainResolver;
use App\Tenancy\TenantDomainsRepository;

/**
 * Private JSON API for tenant custom booking domains (session-authenticated,
 * booking_page.manage permission). Verification is DNS TXT at
 * _albabook-verify.<domain>; only verified rows can be activated, and only
 * 'active' rows resolve a host (TenantRepository::findByDomain).
 */
final class DomainsApiController
{
    public function __construct(
        private readonly TenantDomainsRepository $domains,
        private readonly TenantDomainResolver $resolver,
        private readonly FeatureGate $features,
        private readonly Config $config,
    ) {}

    /** Custom hosts can only be served once the edge supports them. */
    private function platformEnabled(): bool
    {
        return $this->config->bool('tenancy.custom_domains_enabled');
    }

    private function comingSoon(): Response
    {
        return ApiResponse::error(
            'FEATURE_NOT_AVAILABLE',
            'Custom booking domains are coming soon. Your booking page works on its AlbaBook link meanwhile.',
            403,
        );
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view domains.', 403);
        }

        return ApiResponse::ok([
            'domains' => array_map($this->shape(...), $this->domains->listForTenant($tenant->tenantId)),
            // Plan entitlement AND platform availability (the edge must be
            // able to serve custom hosts before anyone can add one).
            'feature_enabled' => $this->platformEnabled()
                && $this->features->enabled($tenant->tenantId, 'custom_booking_domain'),
            'platform_available' => $this->platformEnabled(),
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage domains.', 403);
        }
        if (!$this->platformEnabled()) {
            return $this->comingSoon();
        }
        if (!$this->features->enabled($tenant->tenantId, 'custom_booking_domain')) {
            return ApiResponse::error('FEATURE_NOT_AVAILABLE', 'Custom booking domains are not included in your plan.', 403);
        }

        $domain = $this->resolver->normalise((string) $request->input('domain', ''));
        if ($domain === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Enter a valid domain (e.g. bookings.yourbusiness.co.ke). Platform domains cannot be used.', 422, [
                'domain' => 'Invalid or reserved domain.',
            ]);
        }
        // An unverified claim lapses after the TTL, so nobody can park a
        // domain they do not control and block its real owner.
        $this->domains->releaseStaleClaim($domain, $this->config->int('tenancy.domain_claim_ttl_hours', 72));
        if ($this->domains->findByDomain($domain) !== null) {
            return ApiResponse::error('VALIDATION_FAILED', 'That domain is already registered.', 422, [
                'domain' => 'That domain is already registered.',
            ]);
        }

        $token = bin2hex(random_bytes(16));
        $id = $this->domains->insert($tenant->tenantId, $domain, hash('sha256', $token));

        return ApiResponse::ok([
            'domain' => $this->shape($this->domains->find($tenant->tenantId, $id) ?? []),
            'verification' => $this->verificationPayload($domain, $token),
        ], 201);
    }

    /** Regenerate the verification token (the token is only shown at issue).
     * @param array<string, string> $params */
    public function rotateToken(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }

        $token = bin2hex(random_bytes(16));
        $this->domains->rotateToken($tenant->tenantId, (int) $row['id'], hash('sha256', $token));

        return ApiResponse::ok(['verification' => $this->verificationPayload((string) $row['domain'], $token)]);
    }

    /** Check DNS for the TXT record and mark the domain verified.
     * @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }

        $domain = (string) $row['domain'];
        $expectedHash = (string) $row['verification_token_hash'];
        $recordName = TenantDomainsRepository::TXT_PREFIX . $domain;
        $found = false;

        $records = @dns_get_record($recordName, DNS_TXT);
        if (is_array($records)) {
            foreach ($records as $record) {
                $txt = (string) ($record['txt'] ?? '');
                if ($txt !== '' && hash_equals($expectedHash, hash('sha256', $txt))) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            $this->domains->markCheckFailed($tenant->tenantId, (int) $row['id']);

            return ApiResponse::error(
                'VERIFICATION_FAILED',
                "We could not find the TXT record on {$recordName}. DNS changes can take a while to propagate — add the record and try again.",
                422,
            );
        }

        $this->domains->markVerified($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['domain' => $this->shape($this->domains->find($tenant->tenantId, (int) $row['id']) ?? [])]);
    }

    /** Activate a verified domain so it starts serving the booking page.
     * @param array<string, string> $params */
    public function activate(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }
        if (!$this->platformEnabled()) {
            return $this->comingSoon();
        }
        if ($row['status'] !== 'verified') {
            return ApiResponse::error('INVALID_STATE', 'Only verified domains can be activated.', 409);
        }

        $this->domains->activate($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['domain' => $this->shape($this->domains->find($tenant->tenantId, (int) $row['id']) ?? [])]);
    }

    /** Take a domain offline (it stops resolving immediately).
     * @param array<string, string> $params */
    public function disable(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }

        $this->domains->disable($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['domain' => $this->shape($this->domains->find($tenant->tenantId, (int) $row['id']) ?? [])]);
    }

    /** Mark this domain as the primary custom domain (must be active).
     * @param array<string, string> $params */
    public function primary(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }
        if ($row['status'] !== 'active') {
            return ApiResponse::error('INVALID_STATE', 'Only active domains can be primary.', 409);
        }

        $this->domains->setPrimary($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['domains' => array_map($this->shape(...), $this->domains->listForTenant($tenant->tenantId))]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership, $row] = $this->guarded($request, $params);
        if ($row instanceof Response) {
            return $row;
        }

        $this->domains->delete($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * Shared guard: membership permission + tenant-scoped row lookup.
     * @param array<string, string> $params
     * @return array{0: TenantContext, 1: MembershipContext, 2: array<string,mixed>|Response}
     */
    private function guarded(Request $request, array $params): array
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('booking_page.manage')) {
            return [$tenant, $membership, ApiResponse::error('FORBIDDEN', 'You do not have permission to manage domains.', 403)];
        }
        $row = $this->domains->find($tenant->tenantId, (int) ($params['id'] ?? 0));
        if ($row === null) {
            return [$tenant, $membership, ApiResponse::error('NOT_FOUND', 'Domain not found.', 404)];
        }
        return [$tenant, $membership, $row];
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }

    /** @return array{record: string, token: string} */
    private function verificationPayload(string $domain, string $token): array
    {
        return [
            'record' => TenantDomainsRepository::TXT_PREFIX . $domain,
            'type' => 'TXT',
            'token' => $token,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed> */
    private function shape(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'domain' => $row['domain'] ?? null,
            'status' => $row['status'] ?? null,
            'is_primary' => (bool) ($row['is_primary'] ?? false),
            'tls_status' => $row['tls_status'] ?? 'none',
            'verified_at' => $row['verified_at'] ?? null,
            'last_checked_at' => $row['last_checked_at'] ?? null,
            'record' => isset($row['domain']) ? TenantDomainsRepository::TXT_PREFIX . $row['domain'] : null,
        ];
    }
}
