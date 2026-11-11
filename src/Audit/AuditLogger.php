<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Audit;

use LiveNetworks\BuilderConnector\Auth\RequestContext;

final class AuditLogger
{
    /**
     * Static helper to log an audit entry explicitly (e.g. connect, disconnect, JWKS events).
     *
     * @param array<string, mixed> $details
     */
    public static function log(
        string $operation,
        string $objectType,
        string $objectKey,
        ?int $snapshotId = null,
        string $result = 'ok',
        array $details = [],
        string $actor = 'system',
        ?string $actorName = null,
        string $session = '',
        string $kid = ''
    ): int {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        $table = $wpdb->prefix . 'ln_builder_audit';
        $encodedDetails = $details !== []
            ? wp_json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $wpdb->insert($table, [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'actor' => $actor,
            'actor_name' => $actorName,
            'org' => null,
            'session' => $session,
            'kid' => $kid,
            'operation' => $operation,
            'object_type' => $objectType,
            'object_key' => $objectKey,
            'snapshot_id' => $snapshotId,
            'result' => $result,
            'details' => $encodedDetails,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }
    /**
     * Record a write attempt (succeeded or failed) in `{prefix}ln_builder_audit`.
     *
     * @param array<string, mixed> $details
     */
    public function record(
        string $operation,
        string $objectType,
        string $objectKey,
        string $result,
        ?int $snapshotId = null,
        array $details = []
    ): int {
        global $wpdb;

        $ctx = RequestContext::current();
        $actor = $ctx !== null ? $ctx->actor() : '';
        $actorName = $ctx !== null ? $ctx->actorName() : null;
        $org = $ctx !== null ? $ctx->org() : null;
        $session = $ctx !== null ? $ctx->session() : '';
        $kid = $ctx !== null ? $ctx->kid() : '';

        if ($ctx !== null && !isset($details['request_id'])) {
            $details['request_id'] = $ctx->requestId();
        }

        if ($ctx !== null && $ctx->hasWarnings()) {
            $existingWarnings = isset($details['warnings']) && is_array($details['warnings'])
                ? $details['warnings']
                : [];
            $details['warnings'] = array_merge($existingWarnings, $ctx->warnings());
        }

        // Never store raw exception messages in audit details
        unset($details['error']);

        $table = $wpdb->prefix . 'ln_builder_audit';
        $encodedDetails = $details !== []
            ? wp_json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        $data = [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'actor' => $actor,
            'actor_name' => $actorName,
            'org' => $org,
            'session' => $session,
            'kid' => $kid,
            'operation' => $operation,
            'object_type' => $objectType,
            'object_key' => $objectKey,
            'result' => $result,
            'details' => is_string($encodedDetails) ? $encodedDetails : null,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($snapshotId !== null && $snapshotId > 0) {
            $data['snapshot_id'] = $snapshotId;
            $formats[] = '%d';
        }

        $wpdb->insert($table, $data, $formats);
        return (int) $wpdb->insert_id;
    }

    /**
     * Query audit log entries with pagination and optional filters.
     *
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function query(array $filter): array
    {
        global $wpdb;

        $page = max(1, (int) ($filter['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filter['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $table = $wpdb->prefix . 'ln_builder_audit';
        $where = ['1=1'];
        $params = [];

        if (isset($filter['object']) && is_string($filter['object']) && trim($filter['object']) !== '') {
            $obj = trim($filter['object']);
            if (str_contains($obj, ':')) {
                [$objType, $objKey] = explode(':', $obj, 2);
                $where[] = 'object_type = %s AND object_key = %s';
                $params[] = trim($objType);
                $params[] = trim($objKey);
            } else {
                $where[] = 'object_type = %s';
                $params[] = $obj;
            }
        }

        if (isset($filter['actor']) && is_string($filter['actor']) && trim($filter['actor']) !== '') {
            $where[] = 'actor = %s';
            $params[] = trim($filter['actor']);
        }

        if (isset($filter['org']) && is_string($filter['org']) && trim($filter['org']) !== '') {
            $where[] = 'org = %s';
            $params[] = trim($filter['org']);
        }

        if (isset($filter['session']) && is_string($filter['session']) && trim($filter['session']) !== '') {
            $where[] = 'session = %s';
            $params[] = trim($filter['session']);
        }

        if (isset($filter['operation']) && is_string($filter['operation']) && trim($filter['operation']) !== '') {
            $where[] = 'operation = %s';
            $params[] = trim($filter['operation']);
        }

        if (isset($filter['result']) && is_string($filter['result']) && trim($filter['result']) !== '') {
            $where[] = 'result = %s';
            $params[] = trim($filter['result']);
        }

        if (isset($filter['from']) && is_string($filter['from']) && trim($filter['from']) !== '') {
            $fromTs = strtotime(trim($filter['from']));
            if ($fromTs !== false) {
                $where[] = 'created_at >= %s';
                $params[] = gmdate('Y-m-d H:i:s', $fromTs);
            }
        }

        if (isset($filter['to']) && is_string($filter['to']) && trim($filter['to']) !== '') {
            $toTs = strtotime(trim($filter['to']));
            if ($toTs !== false) {
                $where[] = 'created_at <= %s';
                $params[] = gmdate('Y-m-d H:i:s', $toTs);
            }
        }

        $whereSql = implode(' AND ', $where);

        if ($params !== []) {
            $countSql = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", ...$params);
            $rowsSql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d",
                ...array_merge($params, [$perPage, $offset])
            );
        } else {
            $countSql = "SELECT COUNT(*) FROM {$table}";
            $rowsSql = $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $perPage,
                $offset
            );
        }

        $total = (int) $wpdb->get_var($countSql);
        $rows = $wpdb->get_results($rowsSql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = [];
        foreach ($rows as $row) {
            $createdUtc = isset($row['created_at']) && is_string($row['created_at'])
                ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['created_at'] . ' UTC'))
                : gmdate('Y-m-d\TH:i:s\Z');

            $snapId = isset($row['snapshot_id']) && $row['snapshot_id'] !== null && (int) $row['snapshot_id'] > 0
                ? 'snp_' . (int) $row['snapshot_id']
                : null;

            $decodedDetails = null;
            if (isset($row['details']) && is_string($row['details']) && $row['details'] !== '') {
                $decoded = json_decode($row['details'], true);
                if (is_array($decoded)) {
                    $decodedDetails = $decoded;
                }
            }

            $objType = (string) ($row['object_type'] ?? '');
            $objKey = (string) ($row['object_key'] ?? '');

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => $createdUtc,
                'actor' => (string) ($row['actor'] ?? ''),
                'actor_name' => isset($row['actor_name']) && $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
                'org' => isset($row['org']) && $row['org'] !== null ? (string) $row['org'] : null,
                'session' => (string) ($row['session'] ?? ''),
                'kid' => (string) ($row['kid'] ?? ''),
                'operation' => (string) ($row['operation'] ?? ''),
                'object' => $objType !== '' && $objKey !== '' ? "{$objType}:{$objKey}" : $objType,
                'object_type' => $objType,
                'object_key' => $objKey,
                'snapshot_id' => $snapId,
                'result' => (string) ($row['result'] ?? ''),
                'details' => $decodedDetails,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
