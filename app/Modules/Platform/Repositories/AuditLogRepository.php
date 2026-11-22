<?php

declare(strict_types=1);

namespace App\Modules\Platform\Repositories;

use App\Core\Database\Database;

/** Read-side for the platform audit-log viewer. */
final class AuditLogRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{action?: string, actor_type?: string, tenant?: string, from?: string, to?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'l.action LIKE :action';
            $params['action'] = '%' . $action . '%';
        }
        $actor = (string) ($filters['actor_type'] ?? '');
        if (in_array($actor, ['tenant_user', 'platform_user', 'support_session', 'api_credential', 'system', 'customer'], true)) {
            $where[] = 'l.actor_type = :actor';
            $params['actor'] = $actor;
        }
        $tenant = trim((string) ($filters['tenant'] ?? ''));
        if ($tenant !== '') {
            $where[] = '(t.name LIKE :tenant OR t.slug LIKE :tenant2)';
            $params['tenant'] = '%' . $tenant . '%';
            $params['tenant2'] = '%' . $tenant . '%';
        }
        $from = trim((string) ($filters['from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $where[] = 'l.created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $where[] = 'l.created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM audit_logs l LEFT JOIN tenants t ON t.id = l.tenant_id WHERE {$whereSql}",
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT l.*, t.name AS tenant_name, t.slug AS tenant_slug
             FROM audit_logs l
             LEFT JOIN tenants t ON t.id = l.tenant_id
             WHERE {$whereSql}
             ORDER BY l.id DESC
             LIMIT :lim OFFSET :off",
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }
}
