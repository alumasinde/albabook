# Tenancy (Phase 2)

Foundational, cross-cutting tenant infrastructure lives here, not in a module
(decisions.md D-019): `TenantContext`, `TenantResolver`, `TenantDomainResolver`,
`TenantRepository`, `TenantMembershipRepository`, `TenantAccessChecker`,
`TenantMiddleware` and `TenantSettingsService`.

See docs/multi-tenancy.md for the contract. Nothing here yet in Phase 1.
