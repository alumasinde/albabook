<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Shared\Http\AppUrl;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailMessage;

/**
 * Authentication emails. Plain text only in Phase 2; templates arrive with the
 * notification module. Links always point at the tenant app host.
 */
final class AuthMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly AppUrl $urls,
        private readonly Config $config,
    ) {}

    public function sendVerification(string $email, string $firstName, string $token, Request $request): void
    {
        $link = $this->urls->app('/verify-email?token=' . urlencode($token), $request);
        $this->send($email, 'Verify your AlbaBook email', [
            "Hi {$firstName},",
            '',
            'Confirm your email address to finish setting up your AlbaBook account:',
            $link,
            '',
            'This link expires in 24 hours. If you did not create an account, you can ignore this email.',
        ]);
    }

    public function sendPasswordReset(string $email, string $firstName, string $token, Request $request): void
    {
        $link = $this->urls->app('/reset-password/' . urlencode($token), $request);
        $this->send($email, 'Reset your AlbaBook password', [
            "Hi {$firstName},",
            '',
            'Use this link to choose a new password:',
            $link,
            '',
            'The link expires in 60 minutes and can be used once.',
            'If you did not ask to reset your password, no action is needed.',
        ]);
    }

    public function sendInvitation(
        string $email,
        string $tenantName,
        string $inviterName,
        string $token,
        \DateTimeImmutable $expiresAt,
        Request $request,
    ): void {
        $link = $this->urls->app('/invite/' . urlencode($token), $request);
        $this->send($email, "{$inviterName} invited you to {$tenantName} on AlbaBook", [
            "{$inviterName} invited you to join {$tenantName} on AlbaBook.",
            '',
            'Accept the invitation here:',
            $link,
            '',
            'The invitation expires on ' . $expiresAt->format('j F Y \a\t H:i') . ' UTC.',
            'If you were not expecting this invitation, you can ignore this email.',
        ]);
    }

    public function sendMfaDisabled(string $email, string $firstName): void
    {
        $this->send($email, 'Two-factor authentication was turned off', [
            "Hi {$firstName},",
            '',
            'Two-factor authentication has been disabled for your AlbaBook account.',
            'If this was not you, reset your password immediately and contact support.',
        ]);
    }

    public function sendPasswordChanged(string $email, string $firstName): void
    {
        $this->send($email, 'Your AlbaBook password was changed', [
            "Hi {$firstName},",
            '',
            'The password for your AlbaBook account was changed and other sessions were signed out.',
            'If this was not you, reset your password immediately and contact support.',
        ]);
    }

    /** @param list<string> $lines */
    private function send(string $to, string $subject, array $lines): void
    {
        $from = $this->config->string('mail.from_address');
        $this->mailer->send(new MailMessage(
            to: $to,
            subject: $subject,
            textBody: implode("\n", $lines) . "\n",
            replyTo: $from === '' ? null : $from,
        ));
    }
}
