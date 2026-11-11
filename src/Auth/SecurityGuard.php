<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Auth;

final class SecurityGuard
{
    public const OPTION_IP_ALLOWLIST = 'ln_builder_ip_allowlist';
    public const OPTION_TRUSTED_PROXIES = 'ln_builder_trusted_proxies';
    public const OPTION_RATE_LIMIT_READ = 'ln_builder_rate_limit_read';
    public const OPTION_RATE_LIMIT_WRITE = 'ln_builder_rate_limit_write';
    public const OPTION_RATE_LIMIT_ACTOR_WRITE = 'ln_builder_rate_limit_actor_write';

    public const DEFAULT_RATE_LIMIT_READ = 120;
    public const DEFAULT_RATE_LIMIT_WRITE = 20;
    public const DEFAULT_RATE_LIMIT_ACTOR_WRITE = 20;
    public const DEFAULT_RATE_LIMIT_FAILED_IP = 60; // 60 failed requests / min per IP

    /**
     * Check failed request rate limit for the client IP (anti-brute-force).
     *
     * @return array{ok: bool, retry_after?: int, ip?: string}
     */
    public static function checkFailedIpRateLimit(?\WP_REST_Request $request = null, ?int $now = null): array
    {
        if (!function_exists('get_option')) {
            return ['ok' => true];
        }

        $clientIp = self::resolveClientIp($request);
        if ($clientIp === '') {
            return ['ok' => true];
        }

        $now = $now ?? time();
        $minuteBucket = (int) floor($now / 60);
        $retryAfter = max(1, 60 - ($now % 60));

        $ipHash = substr(hash('sha256', $clientIp), 0, 16);
        $key = sprintf('_ln_builder_rl_fail_ip_%s_%d', $ipHash, $minuteBucket);

        $current = (int) get_option($key, 0);
        if ($current >= self::DEFAULT_RATE_LIMIT_FAILED_IP) {
            return ['ok' => false, 'retry_after' => $retryAfter, 'ip' => $clientIp];
        }

        return ['ok' => true, 'ip' => $clientIp];
    }

    /**
     * Record a failed authentication / dispatch attempt from the client IP.
     */
    public static function recordFailedAttempt(?\WP_REST_Request $request = null, ?int $now = null): void
    {
        if (!function_exists('get_option')) {
            return;
        }

        $clientIp = self::resolveClientIp($request);
        if ($clientIp === '') {
            return;
        }

        $now = $now ?? time();
        $minuteBucket = (int) floor($now / 60);
        $ipHash = substr(hash('sha256', $clientIp), 0, 16);
        $key = sprintf('_ln_builder_rl_fail_ip_%s_%d', $ipHash, $minuteBucket);

        self::incrementCounter($key);
    }

    /**
     * Check whether the current client IP is permitted by `ln_builder_ip_allowlist`.
     * Runs BEFORE JWT verification and JTI insertion.
     *
     * @return array{ok: bool, ip: string}
     */
    public static function checkIpAllowlist(?\WP_REST_Request $request = null): array
    {
        $allowlist = self::parseRuleList(function_exists('get_option') ? get_option(self::OPTION_IP_ALLOWLIST, []) : []);
        $clientIp = self::resolveClientIp($request);

        if ($allowlist === []) {
            return ['ok' => true, 'ip' => $clientIp];
        }

        if ($clientIp === '') {
            return ['ok' => false, 'ip' => ''];
        }

        foreach ($allowlist as $rule) {
            if (self::ipMatchesRule($clientIp, $rule)) {
                return ['ok' => true, 'ip' => $clientIp];
            }
        }

        return ['ok' => false, 'ip' => $clientIp];
    }

    /**
     * Enforce per-kid and per-actor rate limits:
     * - General bucket: `ln_builder_rate_limit_read` (default 120 req/min) for all requests per kid.
     * - Write bucket: `ln_builder_rate_limit_write` (default 20 req/min) for POST/PUT/PATCH/DELETE per kid (excluding POST /validate).
     * - Per-actor write bucket: `ln_builder_rate_limit_actor_write` (default 20 writes/min) for mutating requests per act.
     *
     * @return array{ok: bool, retry_after?: int, scope?: 'key'|'actor'}
     */
    public static function checkRateLimit(
        string $kid,
        string $httpMethod,
        string $routeRelativePath,
        ?int $now = null,
        ?string $actor = null
    ): array {
        if (!function_exists('get_option')) {
            return ['ok' => true];
        }

        $now = $now ?? time();
        $minuteBucket = (int) floor($now / 60);
        $retryAfter = max(1, 60 - ($now % 60));

        $readLimit = (int) get_option(self::OPTION_RATE_LIMIT_READ, self::DEFAULT_RATE_LIMIT_READ);
        if ($readLimit <= 0) {
            $readLimit = self::DEFAULT_RATE_LIMIT_READ;
        }

        $writeLimit = (int) get_option(self::OPTION_RATE_LIMIT_WRITE, self::DEFAULT_RATE_LIMIT_WRITE);
        if ($writeLimit <= 0) {
            $writeLimit = self::DEFAULT_RATE_LIMIT_WRITE;
        }

        $actorWriteLimit = (int) get_option(self::OPTION_RATE_LIMIT_ACTOR_WRITE, self::DEFAULT_RATE_LIMIT_ACTOR_WRITE);
        if ($actorWriteLimit <= 0) {
            $actorWriteLimit = self::DEFAULT_RATE_LIMIT_ACTOR_WRITE;
        }

        $method = strtoupper($httpMethod);
        $normRoute = '/' . ltrim($routeRelativePath, '/');
        if ($normRoute !== '/') {
            $normRoute = rtrim($normRoute, '/');
        }

        $isMutating = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $normRoute !== '/validate';

        // Check per-actor write limit first so one user cannot exhaust shared key limit
        if ($isMutating && $actor !== null && $actor !== '') {
            $actorHash = substr(hash('sha256', $actor), 0, 16);
            $actorWriteKey = sprintf('_ln_builder_rl_act_w_%s_%d', $actorHash, $minuteBucket);
            $actorCount = self::incrementCounter($actorWriteKey);
            if ($actorCount > $actorWriteLimit) {
                return [
                    'ok' => false,
                    'retry_after' => $retryAfter,
                    'scope' => 'actor',
                ];
            }
        }

        $kidHash = substr(hash('sha256', $kid), 0, 16);

        // If this is a write request, check per-key write limit
        if ($isMutating) {
            $writeKey = sprintf('_ln_builder_rl_w_%s_%d', $kidHash, $minuteBucket);
            $writeCount = self::incrementCounter($writeKey);
            if ($writeCount > $writeLimit) {
                return [
                    'ok' => false,
                    'retry_after' => $retryAfter,
                    'scope' => 'key',
                ];
            }
        }

        $readKey = sprintf('_ln_builder_rl_r_%s_%d', $kidHash, $minuteBucket);
        $readCount = self::incrementCounter($readKey);
        if ($readCount > $readLimit) {
            return [
                'ok' => false,
                'retry_after' => $retryAfter,
                'scope' => 'key',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Clear all rate-limit counters (used in tests and cleanup).
     */
    public static function resetCounters(?int $olderThanMinute = null): void
    {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
            return;
        }

        if ($olderThanMinute === null) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_ln\_builder\_rl\_%'");
            return;
        }

        // Delete only buckets strictly older than the specified minute
        $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_ln\_builder\_rl\_%'");
        if (is_array($rows) && $rows !== []) {
            $toDelete = [];
            foreach ($rows as $optName) {
                if (preg_match('/\_(\d+)$/', (string) $optName, $m)) {
                    $bucketMin = (int) $m[1];
                    if ($bucketMin < $olderThanMinute) {
                        $toDelete[] = $optName;
                    }
                } else {
                    $toDelete[] = $optName;
                }
            }
            if ($toDelete !== []) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '%s'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", ...$toDelete));
                foreach ($toDelete as $opt) {
                    wp_cache_delete($opt, 'options');
                }
                wp_cache_delete('alloptions', 'options');
            }
        }
    }

    /**
     * Resolve client IP from REMOTE_ADDR, honoring X-Forwarded-For right-to-left ONLY when REMOTE_ADDR is in trusted proxies.
     */
    public static function resolveClientIp(?\WP_REST_Request $request = null): string
    {
        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? trim($_SERVER['REMOTE_ADDR'])
            : '';

        if ($remoteAddr === '') {
            return '';
        }

        $trustedProxies = self::parseRuleList(function_exists('get_option') ? get_option(self::OPTION_TRUSTED_PROXIES, []) : []);
        if ($trustedProxies === [] || !self::ipMatchesAny($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $xff = null;
        if ($request !== null) {
            $xff = $request->get_header('x_forwarded_for');
        }
        if ((!is_string($xff) || trim($xff) === '') && isset($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if (!is_string($xff) || trim($xff) === '') {
            return $remoteAddr;
        }

        $hops = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            static fn(string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));

        if ($hops === []) {
            return $remoteAddr;
        }

        // Walk right-to-left and return the first hop that is NOT a trusted proxy
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = $hops[$i];
            if (!self::ipMatchesAny($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $hops[0];
    }

    /**
     * Check whether an IPv4 or IPv6 address matches a single IP or CIDR rule.
     */
    public static function ipMatchesRule(string $ip, string $rule): bool
    {
        $ip = trim($ip);
        $rule = trim($rule);
        if ($ip === '' || $rule === '') {
            return false;
        }

        if (!str_contains($rule, '/')) {
            $ipBin = @inet_pton($ip);
            $ruleBin = @inet_pton($rule);
            return $ipBin !== false && $ruleBin !== false && $ipBin === $ruleBin;
        }

        [$subnet, $prefixStr] = explode('/', $rule, 2);
        if ($subnet === '' || $prefixStr === '' || !ctype_digit($prefixStr)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8; // 32 for IPv4, 128 for IPv6
        $prefixBits = (int) $prefixStr;
        if ($prefixBits < 0 || $prefixBits > $maxBits) {
            return false;
        }

        if ($prefixBits === 0) {
            return true;
        }

        $fullBytes = intdiv($prefixBits, 8);
        $remBits = $prefixBits % 8;

        if ($fullBytes > 0) {
            if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
                return false;
            }
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function parseRuleList($raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        } else {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param string[] $rules
     */
    private static function ipMatchesAny(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::ipMatchesRule($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Atomically increment a per-minute counter in `{$wpdb->options}`.
     */
    private static function incrementCounter(string $optionName): int
    {
        global $wpdb;

        if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
            return 1;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $optionName
        ));

        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $optionName
        ));

        return is_numeric($val) ? (int) $val : 1;
    }
}
