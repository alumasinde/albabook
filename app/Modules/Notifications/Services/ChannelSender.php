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
    /**
     * @param array{name: string, language: string, params: list<string>}|null $template
     *        WhatsApp: a provider-approved template (required outside Meta's
     *        24-hour customer window). Null sends $text as free-form.
     */
    public function send(int $tenantId, string $channel, string $recipient, string $text, ?array $template = null): void
    {
        $account = $this->resolveAccount($tenantId, $channel);
        if ($account === null) {
            throw new \RuntimeException($channel === 'whatsapp' && $this->whatsappSender($tenantId) === 'business'
                ? 'WhatsApp is set to send from your own number, but no active WhatsApp account is connected (Settings → Notifications).'
                : "No active {$channel} provider account for this business.");
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

        $this->dispatch($account['provider_code'], $account['credentials'], $account['sender_id'], $recipient, $text, $template);
    }

    /**
     * WhatsApp sender mode for a business: 'platform' (AlbaBook's verified
     * number and templates — the default) or 'business' (their own number
     * and templates; plan feature whatsapp_own_number). A downgraded plan
     * falls back to platform automatically.
     */
    public function whatsappSender(int $tenantId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.whatsapp.sender'))
               FROM tenant_settings WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $mode = $stmt->fetchColumn();

        return $mode === 'business' && $this->features->enabled($tenantId, 'whatsapp_own_number')
            ? 'business'
            : 'platform';
    }

    /**
     * The account a send would use, decrypted and ready to dispatch:
     * tenant account first, platform fallback second.
     * @return array{provider_code: string, credentials: array<string, mixed>, sender_id: string, source: string}|null
     */
    public function resolveAccount(int $tenantId, string $channel): ?array
    {
        // WhatsApp follows the explicit sender mode instead of "tenant first":
        // platform mode always uses the platform number (its templates are
        // approved under the platform account); business mode uses only the
        // business's own account — never a silent fallback to the platform
        // number, which would send the business's templates from the wrong
        // account and fail.
        if ($channel === 'whatsapp') {
            if ($this->whatsappSender($tenantId) === 'platform') {
                return $this->platformAccount('whatsapp');
            }
        }

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

        return $channel === 'whatsapp' ? null : $this->platformAccount($channel);
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
    private function dispatch(string $provider, array $credentials, string $senderId, string $recipient, string $text, ?array $template = null): void
    {
        match ($provider) {
            'africastalking_sms' => $this->africasTalking($credentials, $senderId, $recipient, $text),
            'twilio_sms' => $this->twilio($credentials, $senderId, $recipient, $text, false),
            'twilio_whatsapp' => $this->twilio($credentials, $senderId, $recipient, $text, true, $template),
            'whatsapp_cloud' => $this->whatsAppCloud($credentials, $senderId, $recipient, $text, $template),
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
    /**
     * @param array<string, mixed> $creds
     * @param array{name: string, language: string, params: list<string>}|null $template
     *        Twilio WhatsApp templates are Content API items: name = the
     *        ContentSid ("HX…"), params fill {{1}}, {{2}}, ….
     */
    private function twilio(array $creds, string $senderId, string $recipient, string $text, bool $whatsApp, ?array $template = null): void
    {
        $sid = (string) ($creds['account_sid'] ?? '');
        $token = (string) ($creds['auth_token'] ?? '');
        $from = $senderId !== '' ? $senderId : (string) ($creds['from'] ?? '');
        if ($sid === '' || $token === '' || $from === '') {
            throw new \RuntimeException('Twilio account is missing account_sid/auth_token/from.');
        }

        $fields = [
            'From' => $whatsApp ? "whatsapp:{$from}" : $from,
            'To' => $whatsApp ? "whatsapp:{$recipient}" : $recipient,
        ];
        if ($whatsApp && $template !== null && str_starts_with($template['name'], 'HX')) {
            $vars = [];
            foreach ($template['params'] as $i => $value) {
                $vars[(string) ($i + 1)] = self::waParam($value);
            }
            $fields['ContentSid'] = $template['name'];
            $fields['ContentVariables'] = json_encode($vars, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } else {
            $fields['Body'] = $text;
        }

        $response = $this->http->post(
            "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
            ['Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}"), 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("Twilio rejected the message (HTTP {$response['status']}).");
        }
    }

    /**
     * @param array<string, mixed> $creds
     * @param array{name: string, language: string, params: list<string>}|null $template
     */
    private function whatsAppCloud(array $creds, string $senderId, string $recipient, string $text, ?array $template = null): void
    {
        $phoneId = (string) ($creds['phone_number_id'] ?? '');
        $token = (string) ($creds['access_token'] ?? '');
        if ($phoneId === '' || $token === '') {
            throw new \RuntimeException('WhatsApp Cloud account is missing phone_number_id/access_token.');
        }

        // Template message (works any time) when one is mapped; otherwise
        // free-form text, which Meta only delivers inside the 24-hour
        // customer-service window.
        $message = $template !== null
            ? [
                'type' => 'template',
                'template' => [
                    'name' => $template['name'],
                    'language' => ['code' => $template['language']],
                    'components' => $template['params'] === [] ? [] : [[
                        'type' => 'body',
                        'parameters' => array_map(
                            static fn(string $v): array => ['type' => 'text', 'text' => self::waParam($v)],
                            $template['params'],
                        ),
                    ]],
                ],
            ]
            : ['type' => 'text', 'text' => ['body' => $text]];

        $response = $this->http->post(
            "https://graph.facebook.com/v19.0/{$phoneId}/messages",
            ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            json_encode(['messaging_product' => 'whatsapp', 'to' => ltrim($recipient, '+')] + $message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $error = json_decode($response['body'], true);
            $detail = is_array($error) ? (string) ($error['error']['message'] ?? '') : '';
            throw new \RuntimeException("WhatsApp Cloud rejected the message (HTTP {$response['status']})" . ($detail !== '' ? ": {$detail}" : '.'));
        }
    }

    /** WhatsApp template parameters may not contain newlines/tabs or 4+ spaces, max 1024 chars. */
    private static function waParam(string $value): string
    {
        $value = (string) preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = (string) preg_replace('/ {4,}/', '   ', $value);
        $value = trim($value);

        return $value === '' ? '-' : mb_substr($value, 0, 1024);
    }
}
