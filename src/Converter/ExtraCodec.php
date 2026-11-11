<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class ExtraCodec
{
    public const VERSION = 'v1';

    /**
     * Encode non-lean settings / passthrough data into an HMAC-signed opaque token.
     * Bound to siteId + builder + nativeElementName + format version (NOT element ID,
     * so elements can be copied across templates while preventing cross-type tampering).
     *
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, SiteVocabulary $vocab, string $nativeElementName): string
    {
        $canonical = Revision::canonicalJson($payload);
        $compressed = gzdeflate($canonical, 6);
        $rawBytes = $compressed !== false ? $compressed : $canonical;
        $b64Payload = self::base64UrlEncode($rawBytes);

        $signingInput = implode('|', [
            self::VERSION,
            $vocab->siteId(),
            $vocab->builder(),
            $nativeElementName,
            $b64Payload,
        ]);

        $sig = hash_hmac('sha256', $signingInput, $vocab->extraSecret(), true);
        return self::VERSION . '.' . $b64Payload . '.' . self::base64UrlEncode($sig);
    }

    /**
     * Decode and verify an `extra` token.
     * If $expectedNativeName is null, allows passthrough elements that carry their own `_native_name` inside the signed payload.
     *
     * @return array<string, mixed>|null Null when tampered, malformed, or element type mismatched.
     */
    public static function decode(string $token, SiteVocabulary $vocab, ?string $expectedNativeName = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        [, $b64Payload, $b64Sig] = $parts;
        $rawBytes = self::base64UrlDecode($b64Sig);
        $payloadBytes = self::base64UrlDecode($b64Payload);
        if ($rawBytes === null || $payloadBytes === null) {
            return null;
        }

        // Check HMAC signature first before attempting decompression to prevent zip bombs
        // If $expectedNativeName is provided, we can verify signature immediately
        if ($expectedNativeName !== null) {
            $signingInput = implode('|', [
                self::VERSION,
                $vocab->siteId(),
                $vocab->builder(),
                $expectedNativeName,
                $b64Payload,
            ]);
            $expectedSig = hash_hmac('sha256', $signingInput, $vocab->extraSecret(), true);
            if (!hash_equals($expectedSig, $rawBytes)) {
                return null;
            }
        }

        // Cap inflated output at 1 MB to prevent memory exhaustion
        $inflated = @gzinflate($payloadBytes, 1_000_000);
        $jsonStr = $inflated !== false ? $inflated : $payloadBytes;

        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded)) {
            return null;
        }

        $nativeName = $expectedNativeName ?? (isset($decoded['_native_name']) && is_string($decoded['_native_name']) ? $decoded['_native_name'] : '');
        if ($nativeName === '') {
            return null;
        }

        if ($expectedNativeName !== null && isset($decoded['_native_name']) && $decoded['_native_name'] !== $expectedNativeName) {
            return null;
        }

        // If $expectedNativeName was null (passthrough element), verify signature now that nativeName is known
        if ($expectedNativeName === null) {
            $signingInput = implode('|', [
                self::VERSION,
                $vocab->siteId(),
                $vocab->builder(),
                $nativeName,
                $b64Payload,
            ]);
            $expectedSig = hash_hmac('sha256', $signingInput, $vocab->extraSecret(), true);
            if (!hash_equals($expectedSig, $rawBytes)) {
                return null;
            }
        }

        return $decoded;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $input): ?string
    {
        if ($input === '' || preg_match('/[^A-Za-z0-9\-_]/', $input) === 1) {
            return null;
        }
        $remainder = strlen($input) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }
}
