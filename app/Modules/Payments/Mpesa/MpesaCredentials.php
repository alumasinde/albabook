<?php

declare(strict_types=1);

namespace App\Modules\Payments\Mpesa;

/**
 * Decrypted Daraja credentials for one tenant_payment_accounts row.
 *
 *   consumer_key / consumer_secret  Daraja app keys (OAuth)
 *   passkey                         Lipa na M-Pesa Online passkey
 *   environment                     'sandbox' | 'production'
 *   store_number                    Buy Goods only: the head-office/store
 *                                   number that signs STK requests (the till
 *                                   number itself is the PartyB). Paybills
 *                                   sign with the paybill shortcode.
 */
final readonly class MpesaCredentials
{
    public function __construct(
        public string $consumerKey,
        public string $consumerSecret,
        public string $passkey,
        public string $environment,
        public ?string $storeNumber,
    ) {}

    /**
     * Parses the stored JSON (also the free-form JSON the earlier account
     * form accepted, which used varied key spellings).
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $pick = static function (array $data, array $keys): string {
            foreach ($keys as $key) {
                if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                    return trim((string) $data[$key]);
                }
            }

            return '';
        };

        $key = $pick($data, ['consumer_key', 'consumerKey', 'key']);
        $secret = $pick($data, ['consumer_secret', 'consumerSecret', 'secret']);
        $passkey = $pick($data, ['passkey', 'pass_key', 'lnm_passkey']);
        if ($key === '' || $secret === '' || $passkey === '') {
            return null;
        }
        $env = strtolower($pick($data, ['environment', 'env']));
        $store = $pick($data, ['store_number', 'storeNumber', 'head_office', 'business_shortcode']);

        return new self($key, $secret, $passkey, $env === 'production' ? 'production' : 'sandbox', $store !== '' ? $store : null);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            'passkey' => $this->passkey,
            'environment' => $this->environment,
            'store_number' => $this->storeNumber,
        ];
    }

    public function baseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }
}
