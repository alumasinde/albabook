<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Database;
use App\Shared\Http\HttpClient;
use App\Shared\Security\Encrypter;

/**
 * Delivers sms/whatsapp queue rows through the tenant's configured
 * provider account (tenant_notification_accounts + notification_providers).
 * Credentials are stored encrypted; the decrypted JSON shape is
 * provider-specific (api_key/username, auth_token/account_sid, phone_id…).
 */
final class ChannelSender
{
    public function __construct(
        private readonly Database $db,
        private readonly Encrypter $encrypter,
        private readonly HttpClient $http,
    ) {}

    /**
     * Send $text to $recipient on $channel ('sms'|'whatsapp') using the
     * tenant's active provider account. Throws on any failure — the caller
     * records the error and schedules a retry.
     */
    public function send(int $tenantId, string $channel, string $recipient, string $text): void
    {
        $account = $this->account($tenantId, $channel);
        if ($account === null) {
            throw new \RuntimeException("No active {$channel} provider account for this business.");
        }

        $credentials = json_decode(
            $this->encrypter->decrypt((string) $account['credentials_encrypted']),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $senderId = $account['sender_id'] !== null ? (string) $account['sender_id'] : '';

        $this->dispatch((string) $account['provider_code'], $credentials, $senderId, $recipient, $text);
    }

    /** @return array<string, mixed>|null */
    private function account(int $tenantId, string $channel): ?array
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

        return $row === false ? null : $row;
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
