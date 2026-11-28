<?php

declare(strict_types=1);

namespace App\Modules\Tax\Etims;

use App\Core\Database\Database;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Security\Encrypter;
use App\Shared\Support\UuidFactory;
use Psr\Log\LoggerInterface;

/**
 * eTIMS tax invoicing for completed tickets.
 *
 *   queueInvoice()   at checkout, inside the checkout transaction: next
 *                    invoice number under a counter lock, KRA item codes for
 *                    every line, and the full sale request frozen as a
 *                    snapshot. Checkout never waits on KRA.
 *   submitPending()  scheduler: registers new items (saveItem), transmits
 *                    the sale (saveTrnsSalesOsdc), stores KRA's signature;
 *                    exponential backoff on failure.
 *
 * Amounts: prices are VAT-inclusive. Per line, the taxable amount is the
 * inclusive line total after its share of the ticket discount, and VAT is
 * taxable × rate / (100 + rate) — the formula KRA validates against. Tips
 * are not sales and never appear on the invoice.
 */
final class EtimsService
{
    private const MAX_ATTEMPTS = 12;

    public function __construct(
        private readonly Database $db,
        private readonly EtimsClient $client,
        private readonly TillRepository $till,
        private readonly Encrypter $encrypter,
        private readonly UuidFactory $uuids,
        private readonly LoggerInterface $logger,
    ) {}

    public function config(int $tenantId): EtimsConfig
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.kra_pin, JSON_EXTRACT(COALESCE(s.preferences, JSON_OBJECT()), '$.etims') AS etims
               FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id = t.id WHERE t.id = ?",
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch() ?: [];
        $e = json_decode((string) ($row['etims'] ?? '{}'), true);
        $e = is_array($e) ? $e : [];
        $cmc = '';
        if (is_string($e['cmc_key'] ?? null) && $e['cmc_key'] !== '') {
            try {
                $cmc = $this->encrypter->decrypt($e['cmc_key']);
            } catch (\Throwable) {
                $cmc = '';
            }
        }

        return new EtimsConfig(
            enabled: (bool) ($e['enabled'] ?? false),
            environment: ($e['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            tin: strtoupper(trim((string) ($row['kra_pin'] ?? ''))),
            bhfId: (string) ($e['bhf_id'] ?? '00'),
            deviceSerial: (string) ($e['device_serial'] ?? ''),
            cmcKey: $cmc,
            itemClassCode: (string) ($e['item_class_code'] ?? ''),
            pkgUnit: (string) ($e['pkg_unit'] ?? 'NT'),
            qtyUnit: (string) ($e['qty_unit'] ?? 'U'),
            zeroRateTaxType: in_array($e['zero_rate_tax_type'] ?? 'A', ['A', 'C'], true) ? (string) ($e['zero_rate_tax_type'] ?? 'A') : 'A',
        );
    }

    /** @return array<string, mixed> settings view (never exposes the key) */
    public function describe(int $tenantId): array
    {
        $c = $this->config($tenantId);
        $counts = $this->db->pdo()->prepare('SELECT status, COUNT(*) AS n FROM etims_invoices WHERE tenant_id = ? GROUP BY status');
        $counts->execute([$tenantId]);

        return [
            'enabled' => $c->enabled,
            'environment' => $c->environment,
            'tin' => $c->tin,
            'bhf_id' => $c->bhfId,
            'device_serial' => $c->deviceSerial,
            'initialized' => $c->cmcKey !== '',
            'item_class_code' => $c->itemClassCode,
            'pkg_unit' => $c->pkgUnit,
            'qty_unit' => $c->qtyUnit,
            'zero_rate_tax_type' => $c->zeroRateTaxType,
            'service_charge_bp' => $this->till->serviceChargeBp($tenantId),
            'invoice_counts' => array_column($counts->fetchAll(), 'n', 'status'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> field errors
     */
    public function saveSettings(int $tenantId, array $input): array
    {
        $current = $this->config($tenantId);
        $errors = [];
        $env = (string) ($input['environment'] ?? $current->environment);
        $bhf = trim((string) ($input['bhf_id'] ?? $current->bhfId));
        $serial = trim((string) ($input['device_serial'] ?? $current->deviceSerial));
        $cls = trim((string) ($input['item_class_code'] ?? $current->itemClassCode));
        $pkg = strtoupper(trim((string) ($input['pkg_unit'] ?? $current->pkgUnit)));
        $qty = strtoupper(trim((string) ($input['qty_unit'] ?? $current->qtyUnit)));
        $zero = (string) ($input['zero_rate_tax_type'] ?? $current->zeroRateTaxType);
        $enabled = filter_var($input['enabled'] ?? $current->enabled, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($env, ['sandbox', 'production'], true)) {
            $errors['environment'] = 'Choose sandbox or production.';
        }
        if (preg_match('/^\d{2}$/', $bhf) !== 1) {
            $errors['bhf_id'] = 'KRA branch id is two digits (00 for head office).';
        }
        if ($serial !== '' && preg_match('/^[A-Za-z0-9-]{3,50}$/', $serial) !== 1) {
            $errors['device_serial'] = 'Use the device serial exactly as issued by KRA.';
        }
        if ($cls !== '' && preg_match('/^\d{4,10}$/', $cls) !== 1) {
            $errors['item_class_code'] = 'KRA item classification code (digits).';
        }
        if (preg_match('/^[A-Z]{1,3}$/', $pkg) !== 1) {
            $errors['pkg_unit'] = 'KRA packaging unit code, e.g. NT.';
        }
        if (preg_match('/^[A-Z]{1,3}$/', $qty) !== 1) {
            $errors['qty_unit'] = 'KRA quantity unit code, e.g. U.';
        }
        if (!in_array($zero, ['A', 'C'], true)) {
            $errors['zero_rate_tax_type'] = 'A (exempt) or C (zero-rated).';
        }
        if ($enabled) {
            if ($current->tin === '') {
                $errors['enabled'] = 'Add the business KRA PIN (Settings → Profile) first.';
            } elseif ($current->cmcKey === '' || $serial !== $current->deviceSerial || $env !== $current->environment || $bhf !== $current->bhfId) {
                $errors['enabled'] = 'Initialize the device with KRA (after saving these details) before switching eTIMS on.';
            } elseif ($cls === '') {
                $errors['item_class_code'] = 'Required before switching eTIMS on.';
            } elseif (!in_array($this->till->tenantTaxRateBp($tenantId), [0, 800, 1600], true)) {
                $errors['enabled'] = 'The business VAT rate must be 16%, 8% or 0% for eTIMS.';
            } elseif ($this->till->serviceChargeBp($tenantId) > 0) {
                // KRA derives VAT from the taxable amount; an inclusive
                // service charge would make the receipt's VAT disagree with
                // the eTIMS invoice.
                $errors['enabled'] = 'Set the service charge to 0% before switching eTIMS on (eTIMS has no field for a non-tax levy).';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        // Changing the device identity invalidates the communication key.
        $identityChanged = $serial !== $current->deviceSerial || $env !== $current->environment || $bhf !== $current->bhfId;
        $this->writeSettings($tenantId, [
            'enabled' => $enabled,
            'environment' => $env,
            'bhf_id' => $bhf,
            'device_serial' => $serial,
            'item_class_code' => $cls,
            'pkg_unit' => $pkg,
            'qty_unit' => $qty,
            'zero_rate_tax_type' => $zero,
        ], $identityChanged);

        return [];
    }

    /** Device initialization with KRA; stores the communication key encrypted. */
    public function initializeDevice(int $tenantId): void
    {
        $c = $this->config($tenantId);
        if ($c->tin === '' || $c->deviceSerial === '') {
            throw new EtimsException('Add the KRA PIN and the device serial first.');
        }
        $info = $this->client->initialize($c->environment, $c->tin, $c->bhfId, $c->deviceSerial);
        $this->writeSettings($tenantId, [
            'cmc_key' => $this->encrypter->encrypt((string) $info['cmcKey']),
            'sdc_id' => (string) ($info['sdcId'] ?? ''),
            'mrc_no' => (string) ($info['mrcNo'] ?? ''),
            'initialized_at' => gmdate('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * Issue the eTIMS invoice for a completed ticket. MUST run inside the
     * checkout transaction (Database forbids nested transactions). No-op
     * when eTIMS is off; idempotent per appointment.
     */
    public function queueInvoice(int $tenantId, int $appointmentId): void
    {
        $c = $this->config($tenantId);
        if (!$c->ready()) {
            return;
        }
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM etims_invoices WHERE tenant_id = ? AND appointment_id = ?');
        $exists->execute([$tenantId, $appointmentId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        // Counter row lock: gapless sequential invoice numbers per tenant.
        $pdo->prepare('INSERT IGNORE INTO etims_counters (tenant_id) VALUES (?)')->execute([$tenantId]);
        $counter = $pdo->prepare('SELECT next_invoice_no, next_item_seq FROM etims_counters WHERE tenant_id = ? FOR UPDATE');
        $counter->execute([$tenantId]);
        $row = $counter->fetch();
        $invoiceNo = (int) $row['next_invoice_no'];
        $itemSeq = (int) $row['next_item_seq'];

        $appointment = $pdo->prepare(
            'SELECT a.id, a.branch_id, a.reference, a.customer_name_snapshot, a.customer_phone_snapshot,
                    t.name AS tenant_name
               FROM appointments a JOIN tenants t ON t.id = a.tenant_id
              WHERE a.tenant_id = ? AND a.id = ?',
        );
        $appointment->execute([$tenantId, $appointmentId]);
        $a = $appointment->fetch();
        if ($a === false) {
            return;
        }

        $lines = $this->lines($tenantId, $appointmentId);
        if ($lines === []) {
            return; // nothing taxable (e.g. a tip-only ticket)
        }
        $items = [];
        foreach ($lines as $i => $line) {
            [$itemCd, $itemSeq] = $this->itemCode($c, $tenantId, $line, $itemSeq);
            $items[] = $this->itemRow($c, $i + 1, $itemCd, $line);
        }

        $sale = $this->saleBody($c, $invoiceNo, (string) $a['reference'], $a, $items, $this->paymentTypeCode($tenantId, $appointmentId));
        $pdo->prepare('UPDATE etims_counters SET next_invoice_no = ?, next_item_seq = ? WHERE tenant_id = ?')
            ->execute([$invoiceNo + 1, $itemSeq, $tenantId]);
        $pdo->prepare(
            'INSERT INTO etims_invoices (uuid, tenant_id, branch_id, appointment_id, invoice_no, request_payload)
             VALUES (:u, :t, :b, :a, :n, JSON_EXTRACT(:p, \'$\'))',
        )->execute([
            'u' => $this->uuids->generate(), 't' => $tenantId, 'b' => (int) $a['branch_id'],
            'a' => $appointmentId, 'n' => $invoiceNo, 'p' => json_encode($sale, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /** Scheduler: transmit due invoices. Returns how many were attempted. */
    public function submitPending(int $limit = 20): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM etims_invoices
              WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
              ORDER BY id LIMIT :n",
        );
        $stmt->bindValue('n', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $attempted = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            // Lease: claim the row so a parallel scheduler run skips it.
            $claim = $this->db->pdo()->prepare(
                "UPDATE etims_invoices SET next_attempt_at = UTC_TIMESTAMP() + INTERVAL 5 MINUTE
                  WHERE id = ? AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())",
            );
            $claim->execute([(int) $id]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $attempted++;
            $this->submit((int) $id);
        }

        return $attempted;
    }

    /** @return array<string, mixed>|null the invoice for the receipt */
    public function invoiceFor(int $tenantId, int $appointmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_no, status, sdc_id, mrc_no, rcpt_no, tot_rcpt_no, intrl_data, rcpt_sign, sdc_date_time
               FROM etims_invoices WHERE tenant_id = ? AND appointment_id = ?',
        );
        $stmt->execute([$tenantId, $appointmentId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $c = $this->config($tenantId);
        $row['verify_url'] = $row['status'] === 'submitted' && $row['rcpt_sign'] !== null
            ? EtimsClient::verifyUrl($c->environment, $c->tin, $c->bhfId, (string) $row['rcpt_sign'])
            : null;

        return $row;
    }

    private function submit(int $id): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM etims_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if ($invoice === false) {
            return;
        }
        $tenantId = (int) $invoice['tenant_id'];
        $c = $this->config($tenantId);
        if ($c->cmcKey === '') {
            $this->fail($invoice, 'eTIMS is not initialized for this business.');

            return;
        }

        try {
            $sale = json_decode((string) $invoice['request_payload'], true, 512, JSON_THROW_ON_ERROR);
            // Items first: KRA rejects sales of unregistered item codes.
            foreach ((array) ($sale['itemList'] ?? []) as $item) {
                $this->ensureItemRegistered($c, $tenantId, $item);
            }
            $data = $this->client->saveSale($c, $sale);
            $pdo->prepare(
                "UPDATE etims_invoices
                    SET status = 'submitted', response_payload = JSON_EXTRACT(:r, '$'), last_error = NULL,
                        rcpt_no = :rn, tot_rcpt_no = :trn, intrl_data = :id, rcpt_sign = :rs,
                        sdc_date_time = :dt, sdc_id = :sdc, mrc_no = :mrc, submitted_at = UTC_TIMESTAMP(),
                        attempts = attempts + 1
                  WHERE id = :pk",
            )->execute([
                'r' => json_encode($data, JSON_THROW_ON_ERROR),
                'rn' => isset($data['curRcptNo']) ? (int) $data['curRcptNo'] : null,
                'trn' => isset($data['totRcptNo']) ? (int) $data['totRcptNo'] : null,
                'id' => isset($data['intrlData']) ? (string) $data['intrlData'] : null,
                'rs' => isset($data['rcptSign']) ? (string) $data['rcptSign'] : null,
                'dt' => isset($data['sdcDateTime']) ? (string) $data['sdcDateTime'] : null,
                'sdc' => $this->setting($tenantId, 'sdc_id'),
                'mrc' => $this->setting($tenantId, 'mrc_no'),
                'pk' => $id,
            ]);
        } catch (\Throwable $e) {
            $this->fail($invoice, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $invoice */
    private function fail(array $invoice, string $error): void
    {
        $attempts = (int) $invoice['attempts'] + 1;
        $final = $attempts >= self::MAX_ATTEMPTS;
        $this->db->pdo()->prepare(
            'UPDATE etims_invoices SET attempts = :a, last_error = :e, status = :s,
                    next_attempt_at = UTC_TIMESTAMP() + INTERVAL :w SECOND
              WHERE id = :id',
        )->execute([
            'a' => $attempts, 'e' => mb_substr($error, 0, 500), 's' => $final ? 'failed' : 'pending',
            'w' => min(3600, 60 * (2 ** min($attempts, 6))), 'id' => (int) $invoice['id'],
        ]);
        $this->logger->warning('etims.submit.failed', ['invoice' => $invoice['uuid'], 'attempt' => $attempts, 'error' => $error]);
    }

    /** @param array<string, mixed> $item a snapshot itemList row */
    private function ensureItemRegistered(EtimsConfig $c, int $tenantId, array $item): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, item_name, tax_ty_cd, registered_at FROM etims_items WHERE tenant_id = ? AND item_cd = ?');
        $stmt->execute([$tenantId, (string) $item['itemCd']]);
        $row = $stmt->fetch();
        if ($row === false || $row['registered_at'] !== null) {
            return;
        }
        try {
            $this->client->saveItem($c, [
                'tin' => $c->tin, 'bhfId' => $c->bhfId,
                'itemCd' => (string) $item['itemCd'], 'itemClsCd' => (string) $item['itemClsCd'],
                'itemTyCd' => str_starts_with(substr((string) $item['itemCd'], 2), '3') ? '3' : '2',
                'itemNm' => (string) $row['item_name'], 'itemStdNm' => null, 'orgnNatCd' => 'KE',
                'pkgUnitCd' => $c->pkgUnit, 'qtyUnitCd' => $c->qtyUnit, 'taxTyCd' => (string) $row['tax_ty_cd'],
                'btchNo' => null, 'bcd' => null, 'dftPrc' => (float) $item['prc'],
                'grpPrcL1' => null, 'grpPrcL2' => null, 'grpPrcL3' => null, 'grpPrcL4' => null, 'grpPrcL5' => null,
                'addInfo' => null, 'sftyQty' => null, 'isrcAplcbYn' => 'N', 'useYn' => 'Y',
                'regrNm' => 'AlbaBook', 'regrId' => 'albabook', 'modrNm' => 'AlbaBook', 'modrId' => 'albabook',
            ]);
        } catch (EtimsException $e) {
            // Already registered at KRA (e.g. a retried run) counts as done.
            if (!str_contains(strtolower($e->getMessage()), 'exist')) {
                $this->db->pdo()->prepare('UPDATE etims_items SET last_error = ? WHERE id = ?')
                    ->execute([mb_substr($e->getMessage(), 0, 500), (int) $row['id']]);
                throw $e;
            }
        }
        $this->db->pdo()->prepare('UPDATE etims_items SET registered_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?')
            ->execute([(int) $row['id']]);
    }

    /**
     * Invoice lines: services and products with gross (inclusive) amounts,
     * the ticket discount spread pro rata (remainder on the last line).
     * @return list<array{kind: string, ref_id: int, name: string, qty: int, unit_minor: int, gross_minor: int, discount_minor: int, rate_bp: int}>
     */
    private function lines(int $tenantId, int $appointmentId): array
    {
        $pdo = $this->db->pdo();
        $lines = [];
        $booked = $pdo->prepare(
            'SELECT service_id, service_name_snapshot, quantity, unit_amount_minor, tax_rate_bp
               FROM appointment_items WHERE tenant_id = ? AND appointment_id = ? ORDER BY item_position',
        );
        $booked->execute([$tenantId, $appointmentId]);
        foreach ($booked->fetchAll() as $r) {
            $lines[] = [
                'kind' => 'service', 'ref_id' => (int) $r['service_id'], 'name' => (string) $r['service_name_snapshot'],
                'qty' => (int) $r['quantity'], 'unit_minor' => (int) $r['unit_amount_minor'],
                'gross_minor' => (int) $r['unit_amount_minor'] * (int) $r['quantity'], 'discount_minor' => 0,
                'rate_bp' => (int) $r['tax_rate_bp'],
            ];
        }
        $sale = $pdo->prepare(
            "SELECT kind, service_id, product_id, label, quantity, unit_amount_minor, total_minor, tax_rate_bp
               FROM appointment_sale_items WHERE tenant_id = ? AND appointment_id = ? ORDER BY line_no",
        );
        $sale->execute([$tenantId, $appointmentId]);
        $discount = 0;
        foreach ($sale->fetchAll() as $r) {
            if ($r['kind'] === 'discount') {
                $discount += abs((int) $r['total_minor']);
                continue;
            }
            if ($r['kind'] !== 'service' && $r['kind'] !== 'product') {
                continue; // tips are not sales
            }
            $lines[] = [
                'kind' => (string) $r['kind'],
                'ref_id' => (int) ($r['kind'] === 'service' ? $r['service_id'] : $r['product_id']),
                'name' => (string) $r['label'], 'qty' => (int) $r['quantity'], 'unit_minor' => (int) $r['unit_amount_minor'],
                'gross_minor' => (int) $r['total_minor'], 'discount_minor' => 0, 'rate_bp' => (int) $r['tax_rate_bp'],
            ];
        }

        $gross = array_sum(array_column($lines, 'gross_minor'));
        if ($discount > 0 && $gross > 0) {
            $allocated = 0;
            $last = count($lines) - 1;
            foreach ($lines as $i => &$line) {
                $share = $i === $last ? $discount - $allocated : intdiv($discount * $line['gross_minor'], $gross);
                $line['discount_minor'] = min($share, $line['gross_minor']);
                $allocated += $line['discount_minor'];
            }
            unset($line);
        }

        return $lines;
    }

    /**
     * Get or assign the KRA item code for a service/product (registered
     * later by the worker). Format: KE + item type (3 service, 2 product) +
     * packaging unit + quantity unit + 7-digit sequence.
     * @param array{kind: string, ref_id: int, name: string, rate_bp: int} $line
     * @return array{0: string, 1: int} [item code, next sequence]
     */
    private function itemCode(EtimsConfig $c, int $tenantId, array $line, int $seq): array
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare('SELECT item_cd FROM etims_items WHERE tenant_id = ? AND kind = ? AND ref_id = ?');
        $find->execute([$tenantId, $line['kind'], $line['ref_id']]);
        $code = $find->fetchColumn();
        if (is_string($code)) {
            return [$code, $seq];
        }
        $code = 'KE' . ($line['kind'] === 'service' ? '3' : '2') . $c->pkgUnit . $c->qtyUnit . str_pad((string) $seq, 7, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO etims_items (tenant_id, kind, ref_id, item_cd, item_name, tax_ty_cd) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $line['kind'], $line['ref_id'], $code, mb_substr($line['name'], 0, 200), $c->taxType($line['rate_bp'])]);

        return [$code, $seq + 1];
    }

    /**
     * @param array{kind: string, ref_id: int, name: string, qty: int, unit_minor: int, gross_minor: int, discount_minor: int, rate_bp: int} $line
     * @return array<string, mixed>
     */
    private function itemRow(EtimsConfig $c, int $seq, string $itemCd, array $line): array
    {
        $taxable = $line['gross_minor'] - $line['discount_minor'];
        $rate = intdiv($line['rate_bp'], 100);
        $tax = $rate > 0 ? intdiv($taxable * $rate * 2 + (100 + $rate), (100 + $rate) * 2) : 0; // rounded

        return [
            'itemSeq' => $seq,
            'itemCd' => $itemCd,
            'itemClsCd' => $c->itemClassCode,
            'itemNm' => mb_substr($line['name'], 0, 200),
            'bcd' => null,
            'pkgUnitCd' => $c->pkgUnit,
            'pkg' => $line['qty'],
            'qtyUnitCd' => $c->qtyUnit,
            'qty' => $line['qty'],
            'prc' => self::kes($line['unit_minor']),
            'splyAmt' => self::kes($line['gross_minor']),
            'dcRt' => $line['gross_minor'] > 0 ? round($line['discount_minor'] * 100 / $line['gross_minor'], 2) : 0.0,
            'dcAmt' => self::kes($line['discount_minor']),
            'isrccCd' => null, 'isrccNm' => null, 'isrcRt' => null, 'isrcAmt' => null,
            'taxTyCd' => $c->taxType($line['rate_bp']),
            'taxblAmt' => self::kes($taxable),
            'taxAmt' => self::kes($tax),
            'totAmt' => self::kes($taxable),
        ];
    }

    /**
     * @param array<string, mixed> $a appointment row
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function saleBody(EtimsConfig $c, int $invoiceNo, string $reference, array $a, array $items, string $pmtTyCd): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi'));
        $byType = [];
        foreach (array_keys(EtimsConfig::TAX_TYPES) as $t) {
            $byType[$t] = ['taxable' => 0.0, 'tax' => 0.0];
        }
        foreach ($items as $item) {
            $byType[$item['taxTyCd']]['taxable'] += $item['taxblAmt'];
            $byType[$item['taxTyCd']]['tax'] += $item['taxAmt'];
        }
        $sale = [
            'tin' => $c->tin, 'bhfId' => $c->bhfId,
            'trdInvcNo' => $reference, 'invcNo' => $invoiceNo, 'orgInvcNo' => 0,
            'custTin' => null, 'custNm' => mb_substr((string) ($a['customer_name_snapshot'] ?? ''), 0, 60) ?: null,
            'salesTyCd' => 'N', 'rcptTyCd' => 'S', 'pmtTyCd' => $pmtTyCd, 'salesSttsCd' => '02',
            'cfmDt' => $now->format('YmdHis'), 'salesDt' => $now->format('Ymd'), 'stockRlsDt' => null,
            'cnclReqDt' => null, 'cnclDt' => null, 'rfdDt' => null, 'rfdRsnCd' => null,
            'totItemCnt' => count($items),
        ];
        foreach (EtimsConfig::TAX_TYPES as $t => $rate) {
            $sale['taxblAmt' . $t] = round($byType[$t]['taxable'], 2);
            $sale['taxRt' . $t] = (float) $rate;
            $sale['taxAmt' . $t] = round($byType[$t]['tax'], 2);
        }
        $sale += [
            'totTaxblAmt' => round(array_sum(array_column($items, 'taxblAmt')), 2),
            'totTaxAmt' => round(array_sum(array_column($items, 'taxAmt')), 2),
            'totAmt' => round(array_sum(array_column($items, 'totAmt')), 2),
            'prchrAcptcYn' => 'N', 'remark' => null,
            'regrId' => 'albabook', 'regrNm' => 'AlbaBook', 'modrId' => 'albabook', 'modrNm' => 'AlbaBook',
            'receipt' => [
                'custTin' => null,
                'custMblNo' => $a['customer_phone_snapshot'] ?? null,
                'rptNo' => null,
                'trdeNm' => mb_substr((string) $a['tenant_name'], 0, 60),
                'adrs' => null, 'topMsg' => null, 'btmMsg' => 'Thank you',
                'prchrAcptcYn' => 'N',
            ],
            'itemList' => $items,
        ];

        return $sale;
    }

    /** KRA payment type: the method that paid the most. */
    private function paymentTypeCode(int $tenantId, int $appointmentId): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT method FROM payments WHERE tenant_id = ? AND appointment_id = ? AND status = 'succeeded'
              GROUP BY method ORDER BY SUM(amount_minor) DESC LIMIT 1",
        );
        $stmt->execute([$tenantId, $appointmentId]);

        return match ((string) $stmt->fetchColumn()) {
            'cash' => '01',
            'card' => '05',
            'mpesa', 'airtel_money' => '06',
            '' => '02', // unpaid → credit
            default => '07',
        };
    }

    /** @param array<string, mixed> $values */
    private function writeSettings(int $tenantId, array $values, bool $clearKey): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), '$.etims') FROM tenant_settings WHERE tenant_id = ?",
        );
        $stmt->execute([$tenantId]);
        $current = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true);
        $current = is_array($current) ? $current : [];
        if ($clearKey) {
            unset($current['cmc_key'], $current['sdc_id'], $current['mrc_no'], $current['initialized_at']);
        }
        $this->db->pdo()->prepare(
            "UPDATE tenant_settings SET preferences = JSON_SET(COALESCE(preferences, JSON_OBJECT()), '$.etims', JSON_EXTRACT(:v, '$'))
              WHERE tenant_id = :t",
        )->execute(['v' => json_encode($values + $current, JSON_THROW_ON_ERROR), 't' => $tenantId]);
    }

    private function setting(int $tenantId, string $key): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(COALESCE(preferences, JSON_OBJECT()), :p)) FROM tenant_settings WHERE tenant_id = :t',
        );
        $stmt->execute(['p' => '$.etims.' . $key, 't' => $tenantId]);
        $v = $stmt->fetchColumn();

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function kes(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
