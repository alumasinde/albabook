<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Support\UuidFactory;
use App\Shared\Validation\PhoneNumber;

/**
 * Creates the tenant-user account only. Business profile, slug and branch setup
 * are a separate onboarding step using TenantRegistrationService (plan.md §25).
 * Duplicate addresses are never disclosed: callers show the same "check your
 * inbox" response either way (auth.md §8b).
 */
final class RegistrationService
{
    public function __construct(
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly UuidFactory $uuids,
        private readonly EmailVerificationService $verification,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null null when the email already exists
     * @throws ValidationException
     */
    public function register(array $input, Request $request): ?array
    {
        $data = $this->validator->validate($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email'],
            'phone' => ['nullable', 'string', 'phone'],
            'password' => ['required', 'string', 'max:128', 'confirmed'],
            'terms' => ['required', 'accepted'],
        ]);

        $violations = $this->policy->violations((string) $data['password']);
        if ($violations !== []) {
            throw new ValidationException(['password' => $violations[0]]);
        }

        $email = strtolower((string) $data['email']);
        if ($this->users->findByEmail($email) !== null) {
            return null;
        }

        try {
            $user = $this->db->transaction(function () use ($data, $email): array {
                $userId = $this->users->create([
                    'first_name' => (string) $data['first_name'],
                    'middle_name' => $data['middle_name'] === null ? null : (string) $data['middle_name'],
                    'last_name' => (string) $data['last_name'],
                    'email' => $email,
                    'phone_e164' => PhoneNumber::normalise(
                        $data['phone'] === null ? null : (string) $data['phone'],
                    ),
                    'password_hash' => $this->hasher->hash((string) $data['password']),
                    'locale' => 'en',
                ], $this->uuids->generate());

                $row = $this->users->findById($userId);
                if ($row === null) {
                    throw new \RuntimeException('The new user could not be read back.');
                }

                return $row;
            });
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return null;
            }
            throw $e;
        }

        $this->audit->record(new AuditEntry(
            action: AuditAction::REGISTERED,
            actorType: 'tenant_user',
            actorId: (int) $user['id'],
            targetType: 'user',
            targetId: (string) $user['id'],
            request: $request,
        ));
        $this->verification->issue($user, $request);

        return $user;
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        return is_array($e->errorInfo) && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
