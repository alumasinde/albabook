<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\PlatformPermission;
use App\Console\Command;
use App\Console\Output;
use App\Modules\Platform\Repositories\PlatformUserRepository;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Support\UuidFactory;

/**
 * Bootstraps the first platform administrator. The password is read from
 * STDIN, never from an argument or shell history (auth.md §10).
 */
final class PlatformAdminCommand implements Command
{
    public function __construct(
        private readonly PlatformUserRepository $users,
        private readonly UuidFactory $uuids,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
    ) {}

    public function name(): string
    {
        return 'platform:admin';
    }

    public function description(): string
    {
        return 'Create or reset a platform administrator [--email=] [--first-name=] [--last-name=] [--force]';
    }

    public function run(array $args, Output $output): int
    {
        if (self::option($args, 'password') !== null) {
            $output->error('Passwords are read from STDIN only; remove --password.');

            return 64;
        }

        $email = strtolower(trim((string) self::option($args, 'email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->error('Provide a valid address with --email=.');

            return 64;
        }

        $force = in_array('--force', $args, true);
        $existing = $this->users->findByEmail($email);
        if ($existing !== null && !$force) {
            $output->error('That administrator already exists. Pass --force to reset the password.');

            return 1;
        }

        $output->line('Password (at least 12 characters):');
        $password = self::readLine();
        $output->line('Confirm password:');
        $confirmation = self::readLine();

        if ($password === '' || !hash_equals($password, $confirmation)) {
            $output->error('The passwords do not match.');

            return 1;
        }

        $violations = $this->policy->violations($password);
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $output->error($violation);
            }

            return 1;
        }

        $hash = $this->hasher->hash($password);

        if ($existing !== null) {
            $platformUserId = (int) $existing['id'];
            $this->users->updatePasswordHash($platformUserId, $hash);
        } else {
            $platformUserId = $this->users->create([
                'first_name' => (string) (self::option($args, 'first-name') ?? 'Platform'),
                'middle_name' => null,
                'last_name' => (string) (self::option($args, 'last-name') ?? 'Administrator'),
                'email' => $email,
                'password_hash' => $hash,
            ], $this->uuids->generate());
        }

        foreach (PlatformPermission::cases() as $permission) {
            $this->users->grantPermission($platformUserId, $permission->value, null);
        }

        $granted = $this->users->permissionIdentifiers($platformUserId);
        if (count($granted) < count(PlatformPermission::cases())) {
            $output->warn('Some platform permissions are missing. Run `php bin/console db:seed` first.');
        }

        $output->success($existing === null ? 'Platform administrator created.' : 'Platform administrator password reset.');
        $output->line(sprintf('  permissions granted  %d', count($granted)));
        $output->line('Two-step verification is mandatory and is set up at first sign-in.');

        return 0;
    }

    /** @param list<string> $args */
    private static function option(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    private static function readLine(): string
    {
        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
