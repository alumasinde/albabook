<?php

declare(strict_types=1);

namespace App\Modules\Payments\Mpesa;

use App\Shared\Http\HttpClient;

/**
 * Safaricom Daraja (Lipa na M-Pesa Online) client: OAuth, STK push, STK
 * query. Every call goes through HttpClient (https-only, SSRF-guarded,
 * bounded timeouts). Throws DarajaException with Safaricom's message on any
 * non-success so callers can show it to the till operator.
 */
final class DarajaClient
{
    /** @var array<string, array{token: string, expires: int}> per-process token cache */
    private array $tokens = [];

    public function __construct(private readonly HttpClient $http) {}

    /**
     * Sends the payment prompt to the customer's phone.
     * @return array{merchant_request_id: string, checkout_request_id: string, customer_message: string}
     */
    public function stkPush(
        MpesaCredentials $credentials,
        string $businessShortCode,
        string $partyB,
        bool $buyGoods,
        int $amountWholeShillings,
        string $phone254,
        string $callbackUrl,
        string $accountReference,
        string $description,
    ): array {
        [$timestamp, $password] = $this->password($credentials, $businessShortCode);
        $response = $this->call($credentials, '/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $businessShortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $buyGoods ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount' => $amountWholeShillings,
            'PartyA' => $phone254,
            'PartyB' => $partyB,
            'PhoneNumber' => $phone254,
            'CallBackURL' => $callbackUrl,
            // Daraja limits: AccountReference ≤ 12, TransactionDesc ≤ 13.
            'AccountReference' => mb_substr($accountReference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);

        if ((string) ($response['ResponseCode'] ?? '') !== '0' || !isset($response['CheckoutRequestID'])) {
            throw new DarajaException((string) ($response['errorMessage'] ?? $response['ResponseDescription'] ?? 'M-Pesa did not accept the request.'));
        }

        return [
            'merchant_request_id' => (string) ($response['MerchantRequestID'] ?? ''),
            'checkout_request_id' => (string) $response['CheckoutRequestID'],
            'customer_message' => (string) ($response['CustomerMessage'] ?? ''),
        ];
    }

    /**
     * Asks Safaricom for the outcome of a prompt (used when no callback has
     * arrived). Returns null while the customer has not finished.
     * @return array{result_code: string, result_desc: string}|null
     */
    public function stkQuery(MpesaCredentials $credentials, string $businessShortCode, string $checkoutRequestId): ?array
    {
        [$timestamp, $password] = $this->password($credentials, $businessShortCode);
        try {
            $response = $this->call($credentials, '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);
        } catch (DarajaException $e) {
            // "The transaction is being processed" — still waiting on the customer.
            if (str_contains(strtolower($e->getMessage()), 'being processed')) {
                return null;
            }
            throw $e;
        }
        if (!isset($response['ResultCode'])) {
            return null;
        }

        return [
            'result_code' => (string) $response['ResultCode'],
            'result_desc' => (string) ($response['ResultDesc'] ?? ''),
        ];
    }

    /**
     * Password = base64(shortcode + passkey + timestamp); the timestamp is
     * East Africa Time (Safaricom rejects UTC-skewed stamps).
     * @return array{0: string, 1: string}
     */
    private function password(MpesaCredentials $credentials, string $businessShortCode): array
    {
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');

        return [$timestamp, base64_encode($businessShortCode . $credentials->passkey . $timestamp)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function call(MpesaCredentials $credentials, string $path, array $payload): array
    {
        $response = $this->http->post(
            $credentials->baseUrl() . $path,
            ['Authorization' => 'Bearer ' . $this->token($credentials), 'Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
            20,
        );
        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new DarajaException((string) ($decoded['errorMessage'] ?? "M-Pesa returned HTTP {$response['status']}."));
        }

        return $decoded;
    }

    private function token(MpesaCredentials $credentials): string
    {
        $cacheKey = hash('sha256', $credentials->environment . '|' . $credentials->consumerKey);
        $cached = $this->tokens[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires'] > time() + 30) {
            return $cached['token'];
        }

        $response = $this->http->get(
            $credentials->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization' => 'Basic ' . base64_encode($credentials->consumerKey . ':' . $credentials->consumerSecret)],
            15,
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($decoded) || !is_string($decoded['access_token'] ?? null)) {
            throw new DarajaException('M-Pesa rejected the app credentials (check the consumer key and secret).');
        }
        $this->tokens[$cacheKey] = [
            'token' => $decoded['access_token'],
            'expires' => time() + (int) ($decoded['expires_in'] ?? 3599),
        ];

        return $decoded['access_token'];
    }
}
