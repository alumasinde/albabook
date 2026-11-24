<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Modules\Billing\Services\FeatureGate;
use App\Shared\Http\HttpClient;
use App\Shared\Security\Encrypter;
use App\Shared\Validation\PhoneNumber;

/**
 * Delivers sms/whatsapp queue rows through the provider account resolved
 * for the tenant (D-014 hybrid): the tenant's own configured account wins;
 * when they have none the platform-owned account (config/notifications.php)
 * sends with the shared sender identity.
 * Credentials are stored encrypted; the decrypted JSON shape is
 * provider-specific (api_key/username, auth_token/account_sid, phone_id…).
 */
final class ChannelSender
{
    public function __construct(
        private readonly Database $db,
        private readonly Encrypter $encrypter,
        private readonly HttpClient $http,
        private readonly Config $config,
        private readonly FeatureGate $features,
    ) {}

    /**
     * Send $text to $recipient on $channel ('sms'|'whatsapp') using the
     * resolved provider account. Throws on any failure — the caller
     * records the error and schedules a retry.
     */
    public function send(int $tenantId, string $channel, string $recipient, string $text): void
    {
        $account = $this->resolveAccount($tenantId, $channel);
        if ($account === null) {
            throw new \RuntimeException("No active {$channel} provider account for this business.");
        }

        // Anti-pumping: anyone can type any number into a public booking
        // form, so the platform-paid account only sends to the configured
        // destination prefixes (default: Kenya). A tenant's own account is
        // their own cost and is not restricted.
        // Booking snapshots keep the customer's raw input (0712…, 254712…);
        // providers and the prefix check need E.164.
        $recipient = PhoneNumber::normalise($recipient) ?? $recipient;
        if ($account['source'] === 'platform' && !$this->recipientAllowed($channel, $recipient)) {
            throw new RecipientNotAllowedException(
                "Recipient is outside the platform {$channel} destinations; connect your own provider account to message it.",
            );
        }

        $this->dispatch($account['provider_code'], $account['credentials'], $account['sender_id'], $recipient, $text);
    }

    /**
     * The account a send would use, decrypted and ready to dispatch:
     * tenant account first, platform fallback second.
     * @return array{provider_code: string, credentials: array<string, mixed>, sender_id: string, source: string}|null
     */
    public function resolveAccount(int $tenantId, string $channel): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.sender_id, a.credentials_encrypted, p.code AS provider_code
               FROM tenant_notification_accounts a
               JOIN notification_providers p ON p.id = a.provider_id
              WHERE a.tenant_id = :t AND a.status = 'active' AND p.channel = :c AND p.is_active = 1
              ORDER BY a.id LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'c' => $channel]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $senderId = $row['sender_id'] !== null ? (string) $row['sender_id'] : '';
            // Custom sender IDs are a plan feature (sms_custom_sender_id).
            // Settings enforces this at write time too, but a downgrade can
            // leave a stored sender_id behind — the shared platform sender
            // is used instead when the feature is not entitled.
            if ($senderId !== '' && !$this->features->enabled($tenantId, 'sms_custom_sender_id')) {
                $senderId = $this->platformAccount($channel)['sender_id'] ?? '';
            }

            return [
                'provider_code' => (string) $row['provider_code'],
                'credentials' => json_decode(
                    $this->encrypter->decrypt((string) $row['credentials_encrypted']),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'sender_id' => $senderId,
                'source' => 'tenant',
            ];
        }

        return $this->platformAccount($channel);
    }

    /** E.164 recipient whose prefix is in notifications.platform.{channel}.allowed_prefixes. */
    private function recipientAllowed(string $channel, string $recipient): bool
    {
        $number = PhoneNumber::normalise($recipient);
        if ($number === null) {
            return false;
        }
        $prefixes = $this->config->get("notifications.platform.{$channel}.allowed_prefixes", ['+254']);
        $prefixes = is_array($prefixes) ? $prefixes : [];
        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($number, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the platform-owned fallback is configured for a channel. */
    public function platformConfigured(string $channel): bool
    {
        return $this->platformAccount($channel) !== null;
    }

    /**
     * The platform-owned account for a channel, or null when the provider
     * or its required credentials are not configured.
     * @return array{provider_code: string, credentials: array<string, mixed>, sender_id: string, source: string}|null
     */
    private function platformAccount(string $channel): ?array
    {
        $provider = $this->config->string("notifications.platform.{$channel}.provider");
        // Env shorthand: `africastalking` means the `africastalking_sms` provider code.
        $provider = match ($provider) {
            'africastalking' => 'africastalking_sms',
            'twilio' => $channel === 'whatsapp' ? 'twilio_whatsapp' : 'twilio_sms',
            default => $provider,
        };
        if ($provider === '') {
            return null;
        }
        $credentials = $this->config->array("notifications.platform.{$channel}.credentials");

        return [
            'provider_code' => $provider,
            'credentials' => $credentials,
            'sender_id' => $this->config->string("notifications.platform.{$channel}.sender_id"),
            'source' => 'platform',
        ];
    }

    /** @param array<string, mixed> $credentials */
    private function dispatch(string $provider, array $credentials, string $senderId, string $recipient, string $text): void
    {
        match ($provider) {
            'africastalking_sms' => $this->africasTalking($credentials, $senderId, $recipient, $text),
            'twilio_sms' => $this->twilio($credentials, $senderId, $recipient, $text, false),
            'twilio_whatsapp' => $this->twilio($credentials, $senderId, $recipient, $text, true),
            'whatsapp_cloud' => $this->whatsAppCloud($credentials, $senderId, $recipient, $text),
            default => throw new \RuntimeException("Unsupported channel provider: {$provider}"),
        };
    }

    /** @param array<string, mixed> $creds */
    private function africasTalking(array $creds, string $senderId, string $recipient, string $text): void
    {
        $username = (string) ($creds['username'] ?? '');
        $apiKey = (string) ($creds['api_key'] ?? '');
        if ($username === '' || $apiKey === '') {
            throw new \RuntimeException("Africa's Talking account is missing username/api_key.");
        }

        $fields = ['username' => $username, 'to' => $recipient, 'message' => $text];
        if ($senderId !== '') {
            $fields['from'] = $senderId;
        }

        $response = $this->http->post(
            'https://api.africastalking.com/version1/messaging',
            ['apiKey' => $apiKey, 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Africa's Talking rejected the message (HTTP {$response['status']}).");
        }
    }

    /** @param array<string, mixed> $creds */
    private function twilio(array $creds, string $senderId, string $recipient, string $text, bool $whatsApp): void
    {
        $sid = (string) ($creds['account_sid'] ?? '');
        $token = (string) ($creds['auth_token'] ?? '');
        $from = $senderId !== '' ? $senderId : (string) ($creds['from'] ?? '');
        if ($sid === '' || $token === '' || $from === '') {
            throw new \RuntimeException('Twilio account is missing account_sid/auth_token/from.');
        }

        $response = $this->http->post(
            "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
            ['Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}"), 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'From' => $whatsApp ? "whatsapp:{$from}" : $from,
                'To' => $whatsApp ? "whatsapp:{$recipient}" : $recipient,
                'Body' => $text,
            ]),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Twilio rejected the message (HTTP {$response['status']}).");
        }
    }

    /** @param array<string, mixed> $creds */
    private function whatsAppCloud(array $creds, string $senderId, string $recipient, string $text): void
    {
        $phoneId = (string) ($creds['phone_number_id'] ?? '');
        $token = (string) ($creds['access_token'] ?? '');
        if ($phoneId === '' || $token === '') {
            throw new \RuntimeException('WhatsApp Cloud account is missing phone_number_id/access_token.');
        }

        $response = $this->http->post(
            "https://graph.facebook.com/v19.0/{$phoneId}/messages",
            ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $text],
            ], JSON_THROW_ON_ERROR),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("WhatsApp Cloud rejected the message (HTTP {$response['status']}).");
        }
    }
}
