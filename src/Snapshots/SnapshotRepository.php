<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Snapshots;

use LiveNetworks\BuilderConnector\Auth\RequestContext;

final class SnapshotRepository
{
    public const ALLOWED_REASONS = ['create', 'patch', 'replace', 'publish', 'restore', 'design'];

    /**
     * Create a snapshot row in `{prefix}ln_builder_snapshots`.
     *
     * @param array<string, mixed> $payload
     * @return array{id: int, snapshot_id: string}
     */
    public function create(
        string $objectType,
        string $objectKey,
        ?string $area,
        string $revision,
        array $payload,
        string $reason = 'replace'
    ): array {
        global $wpdb;

        if (!in_array($reason, self::ALLOWED_REASONS, true)) {
            $reason = 'replace';
        }

        if (isset($payload['native']) && is_array($payload['native']) && !isset($payload['native_serialized'])) {
            $payload['native_serialized'] = serialize($payload['native']);
        }

        $ctx = RequestContext::current();
        $actor = $ctx !== null ? $ctx->actor() : '';
        $actorName = $ctx !== null ? $ctx->actorName() : null;
        $org = $ctx !== null ? $ctx->org() : null;
        $session = $ctx !== null ? $ctx->session() : '';
        $table = $wpdb->prefix . 'ln_builder_snapshots';

        $encodedPayload = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedPayload)) {
            throw new \RuntimeException('Failed to encode snapshot payload as JSON.');
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'object_type' => $objectType,
                'object_key' => $objectKey,
                'area' => $area,
                'revision' => $revision,
                'payload' => $encodedPayload,
                'reason' => $reason,
                'actor' => $actor,
                'actor_name' => $actorName,
                'org' => $org,
                'session' => $session,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted !== 1) {
            throw new \RuntimeException('Failed to write snapshot to database.');
        }

        $numericId = (int) $wpdb->insert_id;
        return [
            'id' => $numericId,
            'snapshot_id' => 'snp_' . $numericId,
        ];
    }

    /**
     * List snapshots for `(object_type, object_key)` without the heavy payload column.
     *
     * @return list<array<string, mixed>>
     */
    public function listByObject(string $objectType, string $objectKey, int $limit = 50): array
    {
        global $wpdb;

        $limit = min(100, max(1, $limit));
        $table = $wpdb->prefix . 'ln_builder_snapshots';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, object_type, object_key, area, revision, reason, actor, actor_name, org, session, created_at
                 FROM {$table}
                 WHERE object_type = %s AND object_key = %s
                 ORDER BY id DESC
                 LIMIT %d",
                $objectType,
                $objectKey,
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $numId = (int) ($row['id'] ?? 0);
            $createdUtc = isset($row['created_at']) && is_string($row['created_at'])
                ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['created_at'] . ' UTC'))
                : gmdate('Y-m-d\TH:i:s\Z');

            $items[] = [
                'id' => 'snp_' . $numId,
                'object' => (string) $row['object_type'] . ':' . (string) $row['object_key'],
                'object_type' => (string) $row['object_type'],
                'object_key' => (string) $row['object_key'],
                'area' => $row['area'] !== null ? (string) $row['area'] : null,
                'revision' => (string) $row['revision'],
                'reason' => (string) $row['reason'],
                'actor' => (string) $row['actor'],
                'actor_name' => isset($row['actor_name']) && $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
                'org' => isset($row['org']) && $row['org'] !== null ? (string) $row['org'] : null,
                'session' => (string) $row['session'],
                'created_at' => $createdUtc,
            ];
        }

        return $items;
    }

    /**
     * Find a single snapshot by numeric ID or `snp_<id>` string, including decoded payload.
     *
     * @param int|string $idOrSnp
     * @return array<string, mixed>|null
     */
    public function findById($idOrSnp): ?array
    {
        global $wpdb;

        $numericId = self::parseSnapshotId($idOrSnp);
        if ($numericId === null || $numericId <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'ln_builder_snapshots';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $numericId),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $decodedPayload = [];
        if (isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                $decodedPayload = $decoded;
            }
        }

        $createdUtc = isset($row['created_at']) && is_string($row['created_at'])
            ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['created_at'] . ' UTC'))
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'id' => (int) $row['id'],
            'snapshot_id' => 'snp_' . (int) $row['id'],
            'object_type' => (string) $row['object_type'],
            'object_key' => (string) $row['object_key'],
            'area' => $row['area'] !== null ? (string) $row['area'] : null,
            'revision' => (string) $row['revision'],
            'reason' => (string) $row['reason'],
            'actor' => (string) $row['actor'],
            'actor_name' => isset($row['actor_name']) && $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
            'org' => isset($row['org']) && $row['org'] !== null ? (string) $row['org'] : null,
            'session' => (string) $row['session'],
            'created_at' => $createdUtc,
            'payload' => $decodedPayload,
        ];
    }

    /**
     * @param int|string $idOrSnp
     */
    public static function parseSnapshotId($idOrSnp): ?int
    {
        if (is_int($idOrSnp)) {
            return $idOrSnp > 0 ? $idOrSnp : null;
        }
        if (!is_string($idOrSnp)) {
            return null;
        }
        $str = trim($idOrSnp);
        if (str_starts_with($str, 'snp_')) {
            $str = substr($str, 4);
        }
        if ($str === '' || !ctype_digit($str)) {
            return null;
        }
        $val = (int) $str;
        return $val > 0 ? $val : null;
    }

    public function deleteById(int $id): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ln_builder_snapshots';
        $wpdb->delete($table, ['id' => $id], ['%d']);
    }
}
