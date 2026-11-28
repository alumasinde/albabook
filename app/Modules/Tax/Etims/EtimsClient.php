<?php

declare(strict_types=1);

namespace App\Modules\Tax\Etims;

use App\Shared\Http\HttpClient;

/**
 * KRA eTIMS OSCU (Online Sales Control Unit) API client.
 *
 * Every call: POST JSON to {base}/{endpoint} with headers tin / bhfId /
 * cmcKey (the communication key issued at device initialization). KRA
 * answers {resultCd, resultMsg, resultDt, data}; "000" is success (and
 * "001" = "no search result" for lookups).
 *
 * IMPORTANT: field names follow the published OSCU specification; the
 * integration must pass KRA's sandbox certification for third-party
 * integrators before production use.
 */
final class EtimsClient
{
    public function __construct(private readonly HttpClient $http) {}

    public static function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://etims-api.kra.go.ke/etims-api'
            : 'https://etims-api-sbx.kra.go.ke/etims-api';
    }

    /** KRA's public receipt verification page (encoded in the receipt QR). */
    public static function verifyUrl(string $environment, string $tin, string $bhfId, string $rcptSign): string
    {
        $host = $environment === 'production' ? 'https://etims.kra.go.ke' : 'https://etims-sbx.kra.go.ke';

        return $host . '/common/link/etims/receipt/indexEtimsReceiptData?Data=' . rawurlencode($tin . $bhfId . $rcptSign);
    }

    /**
     * Device initialization: exchanges the registered device serial for the
     * communication key (cmcKey). Done once per branch device.
     * @return array<string, mixed> data.info
     */
    public function initialize(string $environment, string $tin, string $bhfId, string $deviceSerial): array
    {
        $data = $this->call($environment, '/selectInitOsdcInfo', [], [
            'tin' => $tin,
            'bhfId' => $bhfId,
            'dvcSrlNo' => $deviceSerial,
        ]);
        $info = $data['info'] ?? null;
        if (!is_array($info) || !is_string($info['cmcKey'] ?? null)) {
            throw new EtimsException('KRA did not return a communication key.');
        }

        return $info;
    }

    /** @param array<string, mixed> $item */
    public function saveItem(EtimsConfig $config, array $item): void
    {
        $this->call($config->environment, '/saveItem', $config->headers(), $item);
    }

    /**
     * Transmits a sale; returns KRA's signed receipt data
     * (curRcptNo, totRcptNo, intrlData, rcptSign, sdcDateTime).
     * @param array<string, mixed> $sale
     * @return array<string, mixed>
     */
    public function saveSale(EtimsConfig $config, array $sale): array
    {
        return $this->call($config->environment, '/saveTrnsSalesOsdc', $config->headers(), $sale);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $environment, string $endpoint, array $headers, array $body): array
    {
        $response = $this->http->post(
            self::baseUrl($environment) . $endpoint,
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            30,
        );
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new EtimsException("KRA eTIMS returned HTTP {$response['status']} with no readable body.");
        }
        $code = (string) ($decoded['resultCd'] ?? '');
        if ($code !== '000') {
            throw new EtimsException(trim('KRA eTIMS ' . $code . ': ' . (string) ($decoded['resultMsg'] ?? 'request rejected')), $code);
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
