<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Auth;

use LiveNetworks\BuilderConnector\Converter\ExtraCodec;

final class KeyRegistry
{
    public const OPTION_KEYS = 'ln_builder_keys';
    public const LAST_USED_PREFIX = 'ln_builder_key_last_used_';
    public const ALLOWED_SCOPES = ['read', 'content:write', 'publish', 'design:write'];

    // ASN.1 DER prefix for Ed25519 SubjectPublicKeyInfo (RFC 8410): 30 2a 30 05 06 03 2b 65 70 03 21 00
    private const ED25519_SPKI_PREFIX_HEX = '302a300506032b6570032100';

    /**
     * Return all registered keys.
     *
     * @return array<int, array{kid: string, iss: string, public_key: string, scopes: string[], status: string, created_at: string, last_used_at?: ?string}>
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION_KEYS, []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || empty($entry['kid'])) {
                continue;
            }
            $kid = (string) $entry['kid'];
            $lastUsed = get_option(self::LAST_USED_PREFIX . $kid, null);
            $entry['last_used_at'] = is_string($lastUsed) && $lastUsed !== '' ? $lastUsed : null;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Find a key by `kid`.
     *
     * @return array{kid: string, iss: string, public_key: string, scopes: string[], status: string, created_at: string}|null
     */
    public static function findByKid(string $kid): ?array
    {
        $jwksKey = JwksClient::findKey($kid);
        if ($jwksKey !== null) {
            return $jwksKey;
        }

        $raw = get_option(self::OPTION_KEYS, []);
        if (!is_array($raw)) {
            return null;
        }
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['kid']) && (string) $entry['kid'] === $kid) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Add a new MCP public key to the registry.
     * Enforces:
     * - Globally unique `kid`
     * - At most 2 active keys per `iss`
     * - Valid 32-byte Ed25519 public key (accepts PEM SPKI, base64, base64url, or 64-char hex)
     *
     * @param string[] $scopes
     * @return array<string, mixed>
     */
    public static function addKey(string $kid, string $iss, string $rawPublicKey, array $scopes): array
    {
        $kid = trim($kid);
        $iss = trim($iss);

        if ($kid === '' || strlen($kid) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $kid) !== 1) {
            throw new \InvalidArgumentException("Invalid 'kid': must be 1–64 ASCII characters ([A-Za-z0-9._:-]).");
        }
        if ($iss === '' || strlen($iss) > 191) {
            throw new \InvalidArgumentException("Invalid 'iss': must be a non-empty string up to 191 characters.");
        }

        $normalizedPubKey = self::normalizePublicKey($rawPublicKey);
        if ($normalizedPubKey === null) {
            throw new \InvalidArgumentException('Invalid Ed25519 public key. Provide a 32-byte base64url/base64 key or PEM SubjectPublicKeyInfo.');
        }

        $validScopes = array_values(array_intersect(self::ALLOWED_SCOPES, $scopes));
        if ($validScopes === []) {
            throw new \InvalidArgumentException('At least one valid scope must be granted.');
        }

        $keys = get_option(self::OPTION_KEYS, []);
        if (!is_array($keys)) {
            $keys = [];
        }

        $activeForIss = 0;
        foreach ($keys as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if (($existing['kid'] ?? '') === $kid) {
                throw new \InvalidArgumentException(sprintf("Key ID '%s' already exists.", $kid));
            }
            if (($existing['iss'] ?? '') === $iss && ($existing['status'] ?? '') === 'active') {
                $activeForIss++;
            }
        }

        if ($activeForIss >= 2) {
            throw new \InvalidArgumentException(sprintf("Issuer '%s' already has 2 active keys (maximum allowed for key rotation). Disable or remove an old key first.", $iss));
        }

        $newEntry = [
            'kid' => $kid,
            'iss' => $iss,
            'public_key' => $normalizedPubKey,
            'scopes' => $validScopes,
            'status' => 'active',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $keys[] = $newEntry;
        update_option(self::OPTION_KEYS, array_values($keys), false);

        return $newEntry;
    }

    public static function setStatus(string $kid, string $status): bool
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Invalid key status.');
        }

        $keys = get_option(self::OPTION_KEYS, []);
        if (!is_array($keys)) {
            return false;
        }

        $targetIndex = null;
        $targetIss = '';
        foreach ($keys as $idx => $entry) {
            if (is_array($entry) && ($entry['kid'] ?? '') === $kid) {
                $targetIndex = $idx;
                $targetIss = (string) ($entry['iss'] ?? '');
                break;
            }
        }

        if ($targetIndex === null) {
            return false;
        }

        if ($status === 'active') {
            $activeCount = 0;
            foreach ($keys as $idx => $entry) {
                if ($idx !== $targetIndex && is_array($entry) && ($entry['iss'] ?? '') === $targetIss && ($entry['status'] ?? '') === 'active') {
                    $activeCount++;
                }
            }
            if ($activeCount >= 2) {
                throw new \InvalidArgumentException(sprintf("Issuer '%s' already has 2 active keys.", $targetIss));
            }
        }

        $keys[$targetIndex]['status'] = $status;
        update_option(self::OPTION_KEYS, array_values($keys), false);
        return true;
    }

    public static function removeKey(string $kid): bool
    {
        $keys = get_option(self::OPTION_KEYS, []);
        if (!is_array($keys)) {
            return false;
        }

        $filtered = [];
        $found = false;
        foreach ($keys as $entry) {
            if (is_array($entry) && ($entry['kid'] ?? '') === $kid) {
                $found = true;
                continue;
            }
            $filtered[] = $entry;
        }

        if ($found) {
            update_option(self::OPTION_KEYS, array_values($filtered), false);
            delete_option(self::LAST_USED_PREFIX . $kid);
        }

        return $found;
    }

    /**
     * Record last-used timestamp in an isolated per-kid option so concurrent requests
     * never overwrite admin edits to `ln_builder_keys`.
     * Throttled to once per minute per key to avoid high write loads on database.
     */
    public static function touchLastUsed(string $kid): void
    {
        $optKey = self::LAST_USED_PREFIX . $kid;
        $current = get_option($optKey, '');
        $now = time();
        if (is_string($current) && $current !== '') {
            $ts = strtotime($current);
            if ($ts !== false && ($now - $ts) < 60) {
                return;
            }
        }
        update_option($optKey, gmdate('Y-m-d\TH:i:s\Z', $now), false);
    }

    /**
     * Normalize PEM SubjectPublicKeyInfo, standard base64, hex, or base64url into 32-byte base64url Ed25519 key.
     */
    public static function normalizePublicKey(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        // 1. PEM format
        if (str_contains($trimmed, '-----BEGIN')) {
            $b64 = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $trimmed);
            if (!is_string($b64) || $b64 === '') {
                return null;
            }
            $der = base64_decode($b64, true);
            if ($der === false) {
                return null;
            }
            $derHex = strtolower(bin2hex($der));
            if (strlen($der) === 44 && str_starts_with($derHex, self::ED25519_SPKI_PREFIX_HEX)) {
                $raw32 = substr($der, 12, 32);
                return ExtraCodec::base64UrlEncode($raw32);
            }
            if (strlen($der) === 32) {
                return ExtraCodec::base64UrlEncode($der);
            }
            return null;
        }

        // 2. 64-character hex string
        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
            $raw = hex2bin($trimmed);
            return $raw !== false && strlen($raw) === 32 ? ExtraCodec::base64UrlEncode($raw) : null;
        }

        // 3. Base64url or standard Base64 (either 32 raw bytes or 44-byte DER SPKI)
        $decoded = ExtraCodec::base64UrlDecode($trimmed);
        if ($decoded === null) {
            $stdDecoded = base64_decode($trimmed, true);
            if ($stdDecoded !== false) {
                $decoded = $stdDecoded;
            }
        }
        if ($decoded === null) {
            return null;
        }

        if (strlen($decoded) === 32) {
            return ExtraCodec::base64UrlEncode($decoded);
        }

        $hex = strtolower(bin2hex($decoded));
        if (strlen($decoded) === 44 && str_starts_with($hex, self::ED25519_SPKI_PREFIX_HEX)) {
            return ExtraCodec::base64UrlEncode(substr($decoded, 12, 32));
        }

        return null;
    }
}
