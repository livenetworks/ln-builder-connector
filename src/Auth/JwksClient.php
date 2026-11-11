<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Auth;

use LiveNetworks\BuilderConnector\Connect\PlatformState;
use LiveNetworks\BuilderConnector\Converter\ExtraCodec;

final class JwksClient
{
    public const OPTION_JWKS_CACHE = 'ln_builder_jwks_cache';
    public const OPTION_LAST_REFRESH_ATTEMPT = 'ln_builder_jwks_last_attempt';
    public const OPTION_LAST_SUCCESSFUL_FETCH = 'ln_builder_jwks_last_success';

    public const THROTTLE_SECONDS = 300; // max once per 5 minutes for unknown kid
    public const STALE_WARNING_SECONDS = 86400; // 24 hours
    public const FAIL_CLOSED_SECONDS = 1209600; // 14 days (14 * 86400)

    /**
     * Look up a public key by kid.
     *
     * @return array{kid: string, iss: string, public_key: string, scopes: string[], status: string}|null
     */
    public static function findKey(string $kid, bool $allowRefresh = true): ?array
    {
        // 1. Check development manual keys override
        $manualKey = self::findManualKey($kid);
        if ($manualKey !== null) {
            return $manualKey;
        }

        // 2. Check cached JWKS
        $cache = self::getCache();
        $now = time();

        // Fail-closed rule: reject all tokens if cache is older than 14 days
        if ($cache !== null && isset($cache['fetched_at'])) {
            $age = $now - (int) $cache['fetched_at'];
            if ($age > self::FAIL_CLOSED_SECONDS) {
                return null;
            }
        }

        if ($cache !== null && isset($cache['keys'][$kid])) {
            return $cache['keys'][$kid];
        }

        // 3. Unknown kid: rate-limited refresh from jwks_uri
        if ($allowRefresh) {
            $jwksUri = PlatformState::jwksUri();
            if ($jwksUri !== '' && self::canRefreshNow($now)) {
                self::refresh($jwksUri, $now);
                // Retry lookup once without further refresh
                return self::findKey($kid, false);
            }
        }

        return null;
    }

    /**
     * Check if a manual key is provided via LN_BUILDER_MANUAL_KEYS constant.
     *
     * @return array{kid: string, iss: string, public_key: string, scopes: string[], status: string}|null
     */
    public static function findManualKey(string $kid): ?array
    {
        if (!defined('LN_BUILDER_MANUAL_KEYS') || !is_string(LN_BUILDER_MANUAL_KEYS) || trim(LN_BUILDER_MANUAL_KEYS) === '') {
            return null;
        }

        $decoded = json_decode(LN_BUILDER_MANUAL_KEYS, true);
        if (!is_array($decoded)) {
            return null;
        }

        $items = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($items as $entry) {
            if (!is_array($entry) || empty($entry['kid']) || (string) $entry['kid'] !== $kid) {
                continue;
            }

            $rawPub = (string) ($entry['public_key'] ?? '');
            $normalizedPub = KeyRegistry::normalizePublicKey($rawPub);
            if ($normalizedPub === null) {
                continue;
            }

            $scopes = isset($entry['scopes']) && is_array($entry['scopes'])
                ? array_values(array_map('strval', $entry['scopes']))
                : ['read', 'content:write', 'publish', 'design:write'];

            return [
                'kid' => $kid,
                'iss' => (string) ($entry['iss'] ?? 'ln-builder-mcp:prod'),
                'public_key' => $normalizedPub,
                'scopes' => $scopes,
                'status' => 'active',
            ];
        }

        return null;
    }

    /**
     * @return array{fetched_at: int, keys: array<string, array{kid: string, iss: string, public_key: string, scopes: string[], status: string}>}|null
     */
    public static function getCache(): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }

        $cached = get_option(self::OPTION_JWKS_CACHE, null);
        if (!is_array($cached) || !isset($cached['keys']) || !is_array($cached['keys'])) {
            return null;
        }

        return $cached;
    }

    public static function canRefreshNow(?int $now = null): bool
    {
        if (!function_exists('get_option')) {
            return true;
        }

        $now = $now ?? time();
        $lastAttempt = (int) get_option(self::OPTION_LAST_REFRESH_ATTEMPT, 0);

        return ($now - $lastAttempt) >= self::THROTTLE_SECONDS;
    }

    /**
     * Validate whether a JWKS URI is safe and trusted (SSRF protection).
     */
    public static function isTrustedJwksUri(string $uri): bool
    {
        $parsed = wp_parse_url($uri);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $isLocal = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local';
        if ($scheme !== 'https' && !($isLocal && $scheme === 'http')) {
            return false;
        }

        // Reject credentials in URL
        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Check private IP ranges unless in local environment
        if (!$isLocal) {
            $ip = filter_var($host, FILTER_VALIDATE_IP);
            if ($ip !== false) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return false;
                }
            }
            if ($host === 'localhost' || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
                return false;
            }
        }

        $panelHost = strtolower((string) parse_url(PlatformState::panelUrl(), PHP_URL_HOST));
        $allowedHosts = array_values(array_unique(array_filter([
            $panelHost,
            'wpconsole.live.net.mk',
            'wpbridge.live.net.mk',
            'livenetworks.mk',
        ])));

        // Allow test domains ending in .example or .test for testing
        if (str_ends_with($host, '.example') || str_ends_with($host, '.test')) {
            $allowedHosts[] = $host;
        }

        $allowedHosts = apply_filters('ln_builder_trusted_jwks_hosts', $allowedHosts, $uri);

        return in_array($host, $allowedHosts, true);
    }

    /**
     * Fetch JWKS from URI, parse Ed25519 keys, and update cache.
     *
     * @return array{success: bool, count: int, error?: string}
     */
    public static function refresh(?string $jwksUri = null, ?int $now = null): array
    {
        $now = $now ?? time();
        if (function_exists('update_option')) {
            update_option(self::OPTION_LAST_REFRESH_ATTEMPT, $now, false);
        }

        $uri = $jwksUri ?? PlatformState::jwksUri();
        if ($uri === '') {
            return ['success' => false, 'count' => 0, 'error' => 'No jwks_uri configured'];
        }

        if (!self::isTrustedJwksUri($uri)) {
            return ['success' => false, 'count' => 0, 'error' => 'Untrusted or invalid JWKS URI (SSRF protection)'];
        }

        if (!function_exists('wp_remote_get')) {
            return ['success' => false, 'count' => 0, 'error' => 'wp_remote_get unavailable'];
        }

        $response = wp_remote_get($uri, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'count' => 0, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            // Check fallback candidates if 404 or non-200
            $candidateUris = [];
            if (str_contains($uri, 'wpconsole.live.net.mk')) {
                $candidateUris[] = 'https://wpbridge.live.net.mk/.well-known/ln-builder/jwks.json';
                $candidateUris[] = 'https://wpconsole.live.net.mk/.well-known/ln-builder/jwks.json';
            }
            if (str_ends_with($uri, '/.well-known/jwks.json')) {
                $candidateUris[] = substr($uri, 0, -strlen('/.well-known/jwks.json')) . '/.well-known/ln-builder/jwks.json';
            }

            foreach ($candidateUris as $candidateUri) {
                if ($candidateUri === $uri) {
                    continue;
                }
                $candResp = wp_remote_get($candidateUri, [
                    'timeout' => 10,
                    'headers' => ['Accept' => 'application/json'],
                ]);
                if (!is_wp_error($candResp) && (int) wp_remote_retrieve_response_code($candResp) === 200) {
                    $candBody = (string) wp_remote_retrieve_body($candResp);
                    $candData = json_decode($candBody, true);
                    if (is_array($candData) && isset($candData['keys']) && is_array($candData['keys'])) {
                        $parsedKeys = self::parseJwksKeys($candData['keys']);
                        if ($parsedKeys !== []) {
                            if (function_exists('update_option')) {
                                update_option(self::OPTION_JWKS_CACHE, [
                                    'fetched_at' => $now,
                                    'keys' => $parsedKeys,
                                ], true);
                                update_option(self::OPTION_LAST_SUCCESSFUL_FETCH, $now, true);
                            }
                            return ['success' => true, 'count' => count($parsedKeys), 'uri' => $candidateUri];
                        }
                    }
                }
            }

            return ['success' => false, 'count' => 0, 'error' => "HTTP {$code} from JWKS URI"];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            return ['success' => false, 'count' => 0, 'error' => 'Invalid JWKS JSON structure'];
        }

        $parsedKeys = self::parseJwksKeys($data['keys']);
        if ($parsedKeys === []) {
            return ['success' => false, 'count' => 0, 'error' => 'No valid Ed25519 OKP keys found in JWKS'];
        }

        if (function_exists('update_option')) {
            update_option(self::OPTION_JWKS_CACHE, [
                'fetched_at' => $now,
                'keys' => $parsedKeys,
            ], true);
            update_option(self::OPTION_LAST_SUCCESSFUL_FETCH, $now, true);
        }

        return ['success' => true, 'count' => count($parsedKeys)];
    }

    /**
     * @param array<int, mixed> $rawKeys
     * @return array<string, array{kid: string, iss: string, public_key: string, scopes: string[], status: string}>
     */
    public static function parseJwksKeys(array $rawKeys): array
    {
        $platformIss = PlatformState::iss();
        $grantedScopes = PlatformState::grantedScopes();
        if ($grantedScopes === []) {
            $grantedScopes = ['read', 'content:write', 'publish', 'design:write'];
        }

        $result = [];
        $seenKids = [];

        foreach ($rawKeys as $k) {
            if (!is_array($k)) {
                continue;
            }

            // Must match RFC 8037: kty=OKP, crv=Ed25519, alg=EdDSA, use=sig (or omitted)
            $kty = $k['kty'] ?? null;
            $crv = $k['crv'] ?? null;
            $alg = $k['alg'] ?? null;
            $use = $k['use'] ?? 'sig';
            $kid = $k['kid'] ?? null;
            $x = $k['x'] ?? null;

            if ($kty !== 'OKP' || $crv !== 'Ed25519' || $alg !== 'EdDSA' || $use !== 'sig') {
                continue;
            }

            if (!is_string($kid) || trim($kid) === '' || strlen($kid) > 64) {
                continue;
            }

            // Reject duplicate kid
            if (isset($seenKids[$kid])) {
                continue;
            }
            $seenKids[$kid] = true;

            if (!is_string($x) || trim($x) === '') {
                continue;
            }

            $rawPubBytes = ExtraCodec::base64UrlDecode(trim($x));
            if ($rawPubBytes === null || strlen($rawPubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            $normalizedPub = ExtraCodec::base64UrlEncode($rawPubBytes);

            $result[$kid] = [
                'kid' => $kid,
                'iss' => $platformIss !== '' ? $platformIss : 'ln-builder-mcp:prod',
                'public_key' => $normalizedPub,
                'scopes' => $grantedScopes,
                'status' => 'active',
            ];
        }

        return $result;
    }

    public static function isStale(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $lastSuccess = (int) get_option(self::OPTION_LAST_SUCCESSFUL_FETCH, 0);
        if ($lastSuccess === 0) {
            $cache = self::getCache();
            if ($cache !== null && isset($cache['fetched_at'])) {
                $lastSuccess = (int) $cache['fetched_at'];
            }
        }

        if ($lastSuccess === 0) {
            return false;
        }

        return (time() - $lastSuccess) > self::STALE_WARNING_SECONDS;
    }

    public static function isExpired(): bool
    {
        $cache = self::getCache();
        if ($cache === null || !isset($cache['fetched_at'])) {
            return false;
        }

        return (time() - (int) $cache['fetched_at']) > self::FAIL_CLOSED_SECONDS;
    }

    public static function clearCache(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_JWKS_CACHE);
            delete_option(self::OPTION_LAST_REFRESH_ATTEMPT);
            delete_option(self::OPTION_LAST_SUCCESSFUL_FETCH);
        }
    }
}
