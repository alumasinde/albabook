<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Api\Repositories\ApiCredentialRepository;
use App\Modules\Api\Services\ApiCredentialRevoker;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Repositories\PasswordResetTokenRepository;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Repositories\VerificationTokenRepository;
use App\Modules\Auth\Services\AuthMailer;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Auth\Services\PasswordResetService;
use App\Modules\Auth\Services\RegistrationService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\AppUrl;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\SecureToken;
use Tests\Integration\Support\Phase2TestCase;
use Tests\Support\RecordingMailer;

/**
 * Registration → email verification → password reset, against the real schema.
 * Registration and reset open their own transactions, so no per-test rollback.
 */
final class AccountLifecycleTest extends Phase2TestCase
{
    private RecordingMailer $mailer;
    private RegistrationService $registration;
    private EmailVerificationService $verification;
    private PasswordResetService $reset;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->wrapInTransaction = false;
        parent::setUp();

        $this->mailer = new RecordingMailer();
        $this->hasher = new PasswordHasher();
        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());
        $policy = new PasswordPolicy($this->config, dirname(__DIR__, 3));
        $limiter = new RateLimiter($this->db, $this->clock);
        $mailer = new AuthMailer($this->mailer, new AppUrl($this->config), $this->config);

        $this->verification = new EmailVerificationService(
            $this->config,
            $this->clock,
            $this->users,
            new VerificationTokenRepository($this->db),
            $mailer,
            $limiter,
            $audit,
        );

        $this->registration = new RegistrationService(
            $this->db,
            new Validator(),
            $this->users,
            $this->hasher,
            $policy,
            $this->uuids,
            $this->verification,
            $audit,
        );

        $this->reset = new PasswordResetService(
            $this->config,
            $this->clock,
            $this->users,
            new PasswordResetTokenRepository($this->db),
            new SessionRepository($this->db),
            new RememberTokenRepository($this->db),
            $this->hasher,
            $policy,
            $limiter,
            $mailer,
            $audit,
            new ApiCredentialRevoker(new ApiCredentialRepository($this->db), $audit),
        );
    }

    public function testRegistrationSendsAVerificationLinkAndHidesDuplicates(): void
    {
        $email = $this->uniqueEmail('new');
        $user = $this->registration->register($this->form($email), $this->request());

        self::assertNotNull($user);
        self::assertNotNull($this->users->findByEmail($email));
        self::assertCount(1, $this->mailer->messages, 'one verification email');

        $duplicate = $this->registration->register($this->form($email), $this->request());

        self::assertNull($duplicate, 'an existing address returns the same silent outcome');
        self::assertCount(1, $this->mailer->messages, 'no second email is sent');
    }

    public function testTheTokenOnlyExistsAsAHashInTheDatabase(): void
    {
        $email = $this->uniqueEmail('hash');
        $this->registration->register($this->form($email), $this->request());

        $token = $this->verificationToken();
        $stored = $this->pdo->query('SELECT token_hash FROM email_verification_tokens')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains(SecureToken::hash($token), array_values($stored));
        self::assertNotContains($token, array_values($stored));
    }

    public function testAFullLifecycleVerifiesTheEmailAndResetsThePassword(): void
    {
        $email = $this->uniqueEmail('lifecycle');
        $this->registration->register($this->form($email), $this->request());
        $userId = (int) ($this->users->findByEmail($email)['id'] ?? 0);

        $verified = $this->verification->verify($this->verificationToken(), $this->request());
        self::assertSame($email, $verified['email']);
        $user = $this->users->findById($userId);
        self::assertNotNull($user);
        self::assertNotNull($user['email_verified_at']);

        try {
            $this->verification->verify($this->verificationToken(), $this->request());
            self::fail('A verification token must be single-use.');
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }

        (new SessionRepository($this->db))->create($userId, SecureToken::hash('open-session'), null, '', '', null);
        $openSession = (int) $this->pdo->lastInsertId();

        $this->reset->request($email, $this->request());
        $resetToken = $this->resetToken();

        try {
            $this->reset->reset($resetToken, 'short', 'short', $this->request());
            self::fail('A password under the policy must be rejected.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('password', $e->errors);
        }

        $this->reset->reset($resetToken, 'a-brand-new-passphrase-9', 'a-brand-new-passphrase-9', $this->request());

        $user = $this->users->findById($userId);
        self::assertNotNull($user);
        self::assertTrue($this->hasher->verify('a-brand-new-passphrase-9', (string) $user['password_hash']));
        self::assertNotNull($this->revokedAt($openSession), 'resetting signs every other session out');
        self::assertCount(3, $this->mailer->messages, 'verification, reset link and password-changed notice');

        $audit = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action AND actor_id = :actor');
        $audit->execute(['action' => AuditAction::PASSWORD_RESET, 'actor' => $userId]);
        self::assertSame(1, (int) $audit->fetchColumn());

        try {
            $this->reset->reset($resetToken, 'another-brand-new-passphrase', 'another-brand-new-passphrase', $this->request());
            self::fail('A reset token must be single-use.');
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUnknownAddressesGetNoResetMail(): void
    {
        $this->reset->request('missing-' . bin2hex(random_bytes(4)) . '@example.test', $this->request());

        self::assertCount(0, $this->mailer->messages);
    }

    public function testTheAccountStatusGatesReset(): void
    {
        $email = $this->uniqueEmail('suspended');
        $this->registration->register($this->form($email), $this->request());
        $this->mailer->clear();
        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE email = :email")->execute(['email' => $email]);

        $this->reset->request($email, $this->request());

        self::assertCount(0, $this->mailer->messages);
    }

    /** @return array<string, mixed> */
    private function form(string $email): array
    {
        return [
            'first_name' => 'Neema',
            'middle_name' => '',
            'last_name' => 'Achieng',
            'email' => $email,
            'phone' => '',
            'password' => 'another-long-secret-9',
            'password_confirmation' => 'another-long-secret-9',
            'terms' => '1',
        ];
    }

    private function verificationToken(): string
    {
        $message = $this->mailer->last();
        self::assertNotNull($message, 'a verification email should have been sent');
        self::assertSame(1, preg_match('#/verify-email\?token=([a-f0-9]{64})#', $message->textBody, $matches));

        return $matches[1] ?? self::fail('No verification token in the email.');
    }

    private function resetToken(): string
    {
        $message = $this->mailer->last();
        self::assertNotNull($message, 'a reset email should have been sent');
        self::assertSame(1, preg_match('#/reset-password/([a-f0-9]{64})#', $message->textBody, $matches));

        return $matches[1] ?? self::fail('No reset token in the email.');
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/register');
    }

    private function revokedAt(int $sessionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT revoked_at FROM user_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
