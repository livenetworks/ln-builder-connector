<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Rest;

final class IdempotencyStore
{
    public const TTL_SECONDS = 86400; // 24 hours
    public const PENDING_STALE_SECONDS = 60;

    public static function computeRequestHash(string $method, string $normalizedHtu, string $rawBody): string
    {
        return hash('sha256', strtoupper($method) . "\n" . $normalizedHtu . "\n" . $rawBody);
    }

    /**
     * Reserve or check an Idempotency-Key for `(idem_key, kid)`.
     *
     * @return array{
     *   state: 'acquired'|'replay'|'mismatch'|'in_flight',
     *   status?: int,
     *   body?: array<string, mixed>
     * }
     */
    public function begin(string $idemKey, string $kid, string $requestHash): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ln_builder_idempotency';
        $nowTs = time();
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
        $cutoff24h = gmdate('Y-m-d H:i:s', $nowTs - self::TTL_SECONDS);
        $cutoffPending = gmdate('Y-m-d H:i:s', $nowTs - self::PENDING_STALE_SECONDS);

        // Opportunistically delete expired or abandoned pending entries for this key
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE idem_key = %s AND kid = %s AND (created_at < %s OR (status_code = 0 AND created_at < %s))",
            $idemKey,
            $kid,
            $cutoff24h,
            $cutoffPending
        ));

        $suppress = $wpdb->suppress_errors(true);
        try {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (idem_key, kid, request_hash, status_code, response, created_at) VALUES (%s, %s, %s, 0, NULL, %s)",
                $idemKey,
                $kid,
                $requestHash,
                $nowUtc
            ));
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        if ($inserted === 1) {
            return ['state' => 'acquired'];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT request_hash, status_code, response FROM {$table} WHERE idem_key = %s AND kid = %s LIMIT 1",
                $idemKey,
                $kid
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return ['state' => 'acquired'];
        }

        $storedHash = (string) ($row['request_hash'] ?? '');
        if (!hash_equals($storedHash, $requestHash)) {
            return ['state' => 'mismatch'];
        }

        $statusCode = (int) ($row['status_code'] ?? 0);
        if ($statusCode === 0) {
            return ['state' => 'in_flight'];
        }

        $decoded = is_string($row['response'] ?? null) ? json_decode($row['response'], true) : null;
        if (!is_array($decoded)) {
            return ['state' => 'in_flight'];
        }

        return [
            'state' => 'replay',
            'status' => $statusCode,
            'body' => $decoded,
        ];
    }

    /**
     * Store the completed response if status is 2xx or 422; otherwise release the reservation so retries can proceed.
     *
     * @param array<string, mixed> $responseEnvelope
     */
    public function complete(string $idemKey, string $kid, int $statusCode, array $responseEnvelope): void
    {
        global $wpdb;

        $isCacheable = ($statusCode >= 200 && $statusCode < 300) || $statusCode === 422;
        if (!$isCacheable) {
            $this->release($idemKey, $kid);
            return;
        }

        $encoded = wp_json_encode($responseEnvelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $this->release($idemKey, $kid);
            return;
        }

        $table = $wpdb->prefix . 'ln_builder_idempotency';
        $wpdb->update(
            $table,
            [
                'status_code' => $statusCode,
                'response' => $encoded,
            ],
            [
                'idem_key' => $idemKey,
                'kid' => $kid,
            ],
            ['%d', '%s'],
            ['%s', '%s']
        );
    }

    public function release(string $idemKey, string $kid): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ln_builder_idempotency';
        $wpdb->delete(
            $table,
            [
                'idem_key' => $idemKey,
                'kid' => $kid,
            ],
            ['%s', '%s']
        );
    }
}
