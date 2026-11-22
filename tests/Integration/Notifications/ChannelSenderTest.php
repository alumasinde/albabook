<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Core\Config\Config;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Notifications\Services\ChannelSender;
use App\Shared\Http\HttpClient;
use App\Shared\Security\Encrypter;
use Tests\Integration\Support\Phase2TestCase;

/**
 * D-014 hybrid channel accounts: a tenant's own provider account wins;
 * the platform-owned account (config/notifications.php) is the fallback;
 * neither configured means the channel cannot send.
 */
final class ChannelSenderTest extends Phase2TestCase
{
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();
        // APP_KEY from phpunit.xml — the same 32 bytes Encrypter derives from.
        $this->encrypter = new Encrypter((string) base64_decode('AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8='));
    }

    private function sender(?Config $config = null): ChannelSender
    {
        return new ChannelSender(
            $this->db,
            $this->encrypter,
            new HttpClient(),
            $config ?? $this->platformConfig(),
            new FeatureGate($this->db),
        );
    }

    private function platformConfig(): Config
    {
        return new Config([
            'notifications' => [
                'platform' => [
                    'sms' => [
                        'provider' => 'africastalking',
                        'credentials' => ['username' => 'albabook', 'api_key' => 'platform-key'],
                        'sender_id' => 'AlbaBook',
                    ],
                    'whatsapp' => ['provider' => '', 'credentials' => [], 'sender_id' => ''],
                ],
            ],
        ]);
    }

    private function providerId(): int
    {
        // DatabaseSeeder owns provider rows; the test DB only has migrations.
        return $this->insert('notification_providers', [
            'code' => 'africastalking_sms',
            'name' => 'Africa\'s Talking SMS',
            'channel' => 'sms',
            'is_active' => 1,
        ]);
    }

    public function testPlatformAccountIsUsedWhenTenantHasNone(): void
    {
        $tenantId = $this->provision($this->createUser($this->uniqueEmail('o1')))['tenant_id'];

        $account = $this->sender()->resolveAccount($tenantId, 'sms');

        self::assertNotNull($account);
        self::assertSame('platform', $account['source']);
        self::assertSame('africastalking_sms', $account['provider_code']);
        self::assertSame('AlbaBook', $account['sender_id']);
        self::assertSame('platform-key', $account['credentials']['api_key']);
    }

    public function testTenantAccountWinsOverPlatform(): void
    {
        $tenantId = $this->provision($this->createUser($this->uniqueEmail('o2')))['tenant_id'];
        $this->insert('tenant_notification_accounts', [
            'tenant_id' => $tenantId,
            'provider_id' => $this->providerId(),
            'sender_id' => 'MYSHOP',
            'credentials_encrypted' => $this->encrypter->encrypt(
                json_encode(['username' => 'myshop', 'api_key' => 'tenant-key'], JSON_THROW_ON_ERROR),
            ),
            'status' => 'active',
        ]);

        $account = $this->sender()->resolveAccount($tenantId, 'sms');

        self::assertNotNull($account);
        self::assertSame('tenant', $account['source']);
        self::assertSame('MYSHOP', $account['sender_id']);
        self::assertSame('tenant-key', $account['credentials']['api_key']);
    }

    public function testDisabledTenantAccountFallsBackToPlatform(): void
    {
        $tenantId = $this->provision($this->createUser($this->uniqueEmail('o3')))['tenant_id'];
        $this->insert('tenant_notification_accounts', [
            'tenant_id' => $tenantId,
            'provider_id' => $this->providerId(),
            'sender_id' => 'MYSHOP',
            'credentials_encrypted' => $this->encrypter->encrypt(
                json_encode(['username' => 'myshop', 'api_key' => 'tenant-key'], JSON_THROW_ON_ERROR),
            ),
            'status' => 'disabled',
        ]);

        $account = $this->sender()->resolveAccount($tenantId, 'sms');

        self::assertNotNull($account);
        self::assertSame('platform', $account['source']);
    }

    public function testTenantSenderIdRequiresTheCustomSenderFeature(): void
    {
        $tenantId = $this->provision($this->createUser($this->uniqueEmail('o5')))['tenant_id'];
        $this->insert('tenant_notification_accounts', [
            'tenant_id' => $tenantId,
            'provider_id' => $this->providerId(),
            'sender_id' => 'MYSHOP',
            'credentials_encrypted' => $this->encrypter->encrypt(
                json_encode(['username' => 'myshop', 'api_key' => 'tenant-key'], JSON_THROW_ON_ERROR),
            ),
            'status' => 'active',
        ]);
        // Plan no longer includes the feature — a stored sender_id from a
        // previous entitlement must not be honoured at send time.
        $this->insert('tenant_feature_overrides', [
            'tenant_id' => $tenantId,
            'feature_key' => 'sms_custom_sender_id',
            'is_enabled' => 0,
        ]);

        $account = $this->sender()->resolveAccount($tenantId, 'sms');

        self::assertNotNull($account);
        self::assertSame('tenant', $account['source']);
        self::assertSame('AlbaBook', $account['sender_id']);
    }

    public function testNoAccountAnywhereReturnsNull(): void
    {
        $tenantId = $this->provision($this->createUser($this->uniqueEmail('o4')))['tenant_id'];
        $empty = new Config(['notifications' => ['platform' => []]]);

        self::assertNull($this->sender($empty)->resolveAccount($tenantId, 'sms'));
        self::assertFalse($this->sender($empty)->platformConfigured('sms'));
        self::assertTrue($this->sender()->platformConfigured('sms'));
    }
}
