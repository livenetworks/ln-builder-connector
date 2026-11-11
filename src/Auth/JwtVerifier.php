<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Auth;

use LiveNetworks\BuilderConnector\Audit\McpLogger;
use LiveNetworks\BuilderConnector\Connect\PlatformState;
use LiveNetworks\BuilderConnector\Converter\ExtraCodec;
use LiveNetworks\BuilderConnector\Install\Installer;

final class JwtVerifier
{
    public const MAX_LIFETIME_SECONDS = 300;
    public const CLOCK_SKEW_SECONDS = 60;

    /** @var callable(string): (?array<string, mixed>) */
    private $keyLookup;

    /** @var callable(string, string, int): bool */
    private $jtiRecorder;

    private string $siteId;

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(string): (?array<string, mixed>))|null $keyLookup
     * @param (callable(string, string, int): bool)|null $jtiRecorder Returns true if JTI is fresh, false if replayed
     * @param (callable(): int)|null $clock
     */
    /** @var (callable(): bool)|null */
    private $connectionChecker;

    /**
     * @param (callable(string): (?array<string, mixed>))|null $keyLookup
     * @param (callable(string, string, int): bool)|null $jtiRecorder Returns true if JTI is fresh, false if replayed
     * @param (callable(): int)|null $clock
     * @param (callable(): bool)|null $connectionChecker
     */
    public function __construct(
        ?string $siteId = null,
        ?callable $keyLookup = null,
        ?callable $jtiRecorder = null,
        ?callable $clock = null,
        ?callable $connectionChecker = null
    ) {
        $this->siteId = $siteId ?? (function_exists('get_option') ? (string) get_option(Installer::OPTION_SITE_ID, '') : '');
        $this->keyLookup = $keyLookup ?? [KeyRegistry::class, 'findByKid'];
        $this->jtiRecorder = $jtiRecorder ?? [self::class, 'recordJtiInDb'];
        $this->clock = $clock ?? static fn(): int => time();
        $this->connectionChecker = $connectionChecker;
    }

    /**
     * Verify a raw Authorization header and request metadata in the exact order of Contract §2.3 / Plugin §6.2.
     *
     * @param array<string, mixed> $queryParams
     * @return array{ok: bool, status?: int, code?: string, message?: string, context?: RequestContext}
     */
    public function verifyRequest(
        ?string $authorizationHeader,
        string $httpMethod,
        string $routeRelativePath,
        array $queryParams,
        string $rawBody,
        string $requiredScope,
        string $requestId = ''
    ): array {
        if ($requestId === '') {
            $requestId = RequestContext::currentRequestId();
        }

        // Step 1: Site connected (active or pending_approval) -> else site_not_connected
        if ($this->connectionChecker !== null) {
            if (!($this->connectionChecker)()) {
                return $this->fail(401, 'site_not_connected', 'Site is not connected to Live Networks MCP panel.');
            }
        } elseif (function_exists('get_option')) {
            $hasDevOverride = (defined('LN_BUILDER_MANUAL_KEYS') && is_string(LN_BUILDER_MANUAL_KEYS) && trim(LN_BUILDER_MANUAL_KEYS) !== '')
                || Installer::isDevAuthEnabled()
                || (KeyRegistry::all() !== []);

            if (!\LiveNetworks\BuilderConnector\Connect\PlatformState::isConnected() && !$hasDevOverride) {
                return $this->fail(401, 'site_not_connected', 'Site is not connected to Live Networks MCP panel.');
            }
        }

        // Step 2: Header present and Bearer
        if ($authorizationHeader === null || trim($authorizationHeader) === '') {
            return $this->fail(401, 'token_missing', 'Missing Authorization header.');
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authorizationHeader), $matches)) {
            return $this->fail(401, 'token_missing', 'Authorization header must use Bearer scheme.');
        }

        $jwt = $matches[1];
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return $this->fail(401, 'token_invalid', 'Malformed JWT structure.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $headerBytes = ExtraCodec::base64UrlDecode($headerB64);
        $payloadBytes = ExtraCodec::base64UrlDecode($payloadB64);
        $sigBytes = ExtraCodec::base64UrlDecode($sigB64);

        if ($headerBytes === null || $payloadBytes === null || $sigBytes === null) {
            return $this->fail(401, 'token_invalid', 'Invalid base64url encoding in JWT.');
        }

        $header = json_decode($headerBytes, true);
        $claims = json_decode($payloadBytes, true);
        if (!is_array($header) || !is_array($claims)) {
            return $this->fail(401, 'token_invalid', 'JWT header or payload is not valid JSON.');
        }

        // Reject unsupported critical headers
        if (isset($header['crit'])) {
            return $this->fail(401, 'token_invalid', 'Unsupported critical header parameter.');
        }

        // Step 2: Decode header; alg must be EdDSA; kid must exist and be active -> else unknown_key
        $alg = $header['alg'] ?? null;
        if ($alg !== 'EdDSA') {
            return $this->fail(401, 'token_invalid', "Unsupported JWT algorithm; expected 'EdDSA'.");
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 64) {
            return $this->fail(401, 'unknown_key', "Missing or invalid 'kid' in JWT header.");
        }

        $keyEntry = ($this->keyLookup)($kid);
        if (!is_array($keyEntry) || ($keyEntry['status'] ?? '') !== 'active') {
            return $this->fail(401, 'unknown_key', sprintf("Key '%s' is unknown or disabled.", $kid));
        }

        $pubKeyBytes = ExtraCodec::base64UrlDecode((string) ($keyEntry['public_key'] ?? ''));
        if ($pubKeyBytes === null || strlen($pubKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $this->fail(401, 'unknown_key', 'Registered public key is invalid.');
        }

        // Step 3: Verify signature with that key -> else token_invalid
        if (strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return $this->fail(401, 'token_invalid', 'Invalid Ed25519 signature length.');
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        if (!sodium_crypto_sign_verify_detached($sigBytes, $signingInput, $pubKeyBytes)) {
            return $this->fail(401, 'token_invalid', 'JWT signature verification failed.');
        }

        // Step 4: iss equals the key's iss (or platform iss); aud equals ln_builder_site_id -> else audience_mismatch
        $iss = $claims['iss'] ?? null;
        $aud = $claims['aud'] ?? null;

        $keyIss = (string) ($keyEntry['iss'] ?? '');
        $platformIss = class_exists(PlatformState::class) ? PlatformState::iss() : '';

        // The token issuer must strictly match the key's declared issuer or the connected platform issuer
        $validIssuers = [];
        if ($keyIss !== '') {
            $validIssuers[] = $keyIss;
        } elseif ($platformIss !== '') {
            $validIssuers[] = $platformIss;
        } else {
            $validIssuers = ['ln-builder-mcp:prod', 'https://wpbridge.live.net.mk', 'https://wpconsole.live.net.mk'];
        }

        $siteKey = class_exists(PlatformState::class)
            ? PlatformState::siteKey()
            : '';
        $validAudiences = array_values(array_filter(array_unique([
            $this->siteId,
            $siteKey,
        ]), static fn(string $s): bool => $s !== ''));

        if (
            !is_string($iss) || $iss === '' || !in_array($iss, $validIssuers, true)
            || !is_string($aud) || $aud === '' || $validAudiences === [] || !in_array($aud, $validAudiences, true)
        ) {
            return $this->fail(401, 'audience_mismatch', 'JWT issuer or audience does not match this site.');
        }

        // Step 5: Time: nbf - 60 <= now <= exp + 60, iat <= now + 60, and exp - iat <= 300
        $iat = $claims['iat'] ?? null;
        $nbf = $claims['nbf'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($nbf) || !is_int($exp)) {
            return $this->fail(401, 'token_invalid', 'Missing or non-integer iat, nbf, or exp claim.');
        }

        $now = (int) ($this->clock)();
        if ($exp < $iat || ($exp - $iat) > self::MAX_LIFETIME_SECONDS || $iat > ($now + self::CLOCK_SKEW_SECONDS)) {
            return $this->fail(401, 'token_invalid', 'Token lifetime exceeds 300 seconds or iat is in the future.');
        }

        if ($now < ($nbf - self::CLOCK_SKEW_SECONDS) || $now > ($exp + self::CLOCK_SKEW_SECONDS)) {
            return $this->fail(401, 'token_expired', 'Token has expired or is not yet valid.');
        }

        // Step 6: htm equals request method; htu equals normalized request path; if bh present, equals base64url(sha256(raw body)) -> else request_mismatch
        $htm = $claims['htm'] ?? null;
        $htu = $claims['htu'] ?? null;
        $expectedHtm = strtoupper($httpMethod);
        $expectedHtu = self::normalizeHtu($routeRelativePath, $queryParams);

        if (!is_string($htm) || !is_string($htu) || $htm !== $expectedHtm || $htu !== $expectedHtu) {
            return $this->fail(
                401,
                'request_mismatch',
                sprintf("JWT htm/htu does not match request (expected %s %s).", $expectedHtm, $expectedHtu)
            );
        }

        if (array_key_exists('bh', $claims)) {
            if (!is_string($claims['bh']) || $claims['bh'] === '') {
                return $this->fail(401, 'request_mismatch', "Invalid 'bh' claim.");
            }
            $expectedBh = ExtraCodec::base64UrlEncode(hash('sha256', $rawBody, true));
            if (!hash_equals($expectedBh, $claims['bh'])) {
                return $this->fail(401, 'request_mismatch', 'Request body hash (bh) does not match payload.');
            }
        }

        // Validate required audit claims (act, sid) and jti format before recording JTI
        $jti = $claims['jti'] ?? null;
        $act = $claims['act'] ?? null;
        $sid = $claims['sid'] ?? null;
        $scopeClaim = $claims['scope'] ?? null;

        if (
            !is_string($jti) || $jti === '' || strlen($jti) > 64 || preg_match('/^[\x21-\x7E]+$/', $jti) !== 1
            || !is_string($act) || strlen($act) > 64 || preg_match('/^[A-Za-z0-9_\-:.@]+$/', $act) !== 1
            || !is_string($sid) || trim($sid) === ''
            || !is_string($scopeClaim)
        ) {
            return $this->fail(401, 'token_invalid', 'Missing or invalid jti, act, sid, or scope claim.');
        }

        // Optional v0.4.0 claims: act_name and org
        $actorName = null;
        if (isset($claims['act_name']) && is_string($claims['act_name'])) {
            $rawName = trim($claims['act_name']);
            if ($rawName !== '') {
                $sanitized = function_exists('sanitize_text_field')
                    ? sanitize_text_field($rawName)
                    : trim(strip_tags($rawName));
                $actorName = function_exists('mb_substr')
                    ? mb_substr($sanitized, 0, 100)
                    : substr($sanitized, 0, 100);
                if ($actorName === '') {
                    $actorName = null;
                }
            }
        }

        $org = null;
        $warnings = [];
        if (array_key_exists('org', $claims) && $claims['org'] !== null && $claims['org'] !== '') {
            $rawOrg = $claims['org'];
            if (is_string($rawOrg) && preg_match('/^[a-z0-9-]{1,64}$/', $rawOrg) === 1) {
                $org = $rawOrg;
            } else {
                $org = null;
                $warnings[] = [
                    'code' => 'invalid_org',
                    'message' => sprintf(
                        "Invalid org claim '%s' ignored; must match ^[a-z0-9-]{1,64}$.",
                        is_scalar($rawOrg) ? (string) $rawOrg : gettype($rawOrg)
                    ),
                ];
            }
        }

        // Step 7: Insert jti -> duplicate = token_replayed
        $recorded = (bool) ($this->jtiRecorder)($jti, $kid, $exp + self::CLOCK_SKEW_SECONDS);
        if (!$recorded) {
            return $this->fail(401, 'token_replayed', 'Token JTI has already been used.');
        }

        // Step 8: Effective scopes = scope claim ∩ key scopes. Endpoint's required scope not included -> 403 scope_denied
        $tokenScopes = array_values(array_filter(explode(' ', trim($scopeClaim)), static fn(string $s): bool => $s !== ''));
        $keyScopes = is_array($keyEntry['scopes'] ?? null) ? $keyEntry['scopes'] : [];
        $effectiveScopes = array_values(array_intersect($keyScopes, $tokenScopes));

        if ($requiredScope !== '') {
            $requiredList = array_map('trim', explode('|', $requiredScope));
            $hasAny = false;
            foreach ($requiredList as $req) {
                if ($req !== '' && in_array($req, $effectiveScopes, true)) {
                    $hasAny = true;
                    break;
                }
            }
            if (!$hasAny) {
                return $this->fail(
                    403,
                    'scope_denied',
                    sprintf("Missing required scope '%s'.", $requiredScope)
                );
            }
        }

        $context = new RequestContext(
            $requestId,
            $kid,
            $iss,
            trim($act),
            trim($sid),
            $effectiveScopes,
            false,
            $actorName,
            $org,
            $warnings
        );

        return [
            'ok' => true,
            'context' => $context,
        ];
    }

    /**
     * WordPress REST permission callback factory for a given required scope.
     *
     * @return callable(\WP_REST_Request): (bool|\WP_Error)
     */
    public static function permissionCallback(string $requiredScope): callable
    {
        return static function (\WP_REST_Request $request) use ($requiredScope) {
            $authHeader = self::extractAuthorizationHeader($request);

            $route = $request->get_route();
            $prefix = '/ln-builder/v1';
            $relPath = str_starts_with($route, $prefix) ? substr($route, strlen($prefix)) : $route;
            if ($relPath === '') {
                $relPath = '/';
            }

            // Dev Auth support (Basic Application Password for the dedicated bridge user only)
            if (
                Installer::isDevAuthEnabled()
                && $authHeader !== null
                && stripos(trim($authHeader), 'Basic ') === 0
            ) {
                $devResult = self::authenticateDevRequest($authHeader, $requiredScope);
                if ($devResult !== true) {
                    McpLogger::logAuth(false, [
                        'type' => 'dev_auth',
                        'route' => $route,
                        'required_scope' => $requiredScope,
                        'message' => is_wp_error($devResult) ? $devResult->get_error_message() : 'Dev auth failed',
                        'code' => is_wp_error($devResult) ? $devResult->get_error_code() : 'token_invalid',
                    ]);
                    return $devResult;
                }
                $rl = SecurityGuard::checkRateLimit('dev', $request->get_method(), $relPath);
                if (!$rl['ok']) {
                    McpLogger::logAuth(false, [
                        'type' => 'dev_auth',
                        'route' => $route,
                        'required_scope' => $requiredScope,
                        'code' => 'rate_limited',
                        'message' => 'Rate limit exceeded for this key.',
                    ]);
                    return new \WP_Error(
                        'rate_limited',
                        'Rate limit exceeded for this key.',
                        ['status' => 429, 'retry_after' => (int) ($rl['retry_after'] ?? 60)]
                    );
                }
                McpLogger::logAuth(true, [
                    'type' => 'dev_auth',
                    'route' => $route,
                    'required_scope' => $requiredScope,
                ]);
                return true;
            }

            $verifier = new self();
            $result = $verifier->verifyRequest(
                $authHeader,
                $request->get_method(),
                $relPath,
                $request->get_query_params(),
                (string) $request->get_body(),
                $requiredScope
            );

            if (!$result['ok']) {
                SecurityGuard::recordFailedAttempt($request);
                McpLogger::logAuth(false, [
                    'type' => 'jwt',
                    'route' => $route,
                    'rel_path' => $relPath,
                    'method' => $request->get_method(),
                    'required_scope' => $requiredScope,
                    'auth_header' => $authHeader,
                    'status' => $result['status'] ?? 401,
                    'code' => $result['code'] ?? 'token_invalid',
                    'message' => $result['message'] ?? 'Authentication failed.',
                    'details' => $result['details'] ?? [],
                ]);
                return new \WP_Error(
                    (string) ($result['code'] ?? 'token_invalid'),
                    (string) ($result['message'] ?? 'Authentication failed.'),
                    ['status' => (int) ($result['status'] ?? 401)]
                );
            }

            /** @var RequestContext $ctx */
            $ctx = $result['context'];
            RequestContext::setCurrent($ctx);
            KeyRegistry::touchLastUsed($ctx->kid());

            $rl = SecurityGuard::checkRateLimit($ctx->kid(), $request->get_method(), $relPath, null, $ctx->actor());
            if (!$rl['ok']) {
                $errorData = [
                    'status' => 429,
                    'retry_after' => (int) ($rl['retry_after'] ?? 60),
                ];
                if (($rl['scope'] ?? '') === 'actor') {
                    $errorData['details'] = ['scope' => 'actor'];
                    $message = 'Rate limit exceeded for this actor.';
                } else {
                    $errorData['details'] = ['scope' => 'key'];
                    $message = 'Rate limit exceeded for this key.';
                }
                McpLogger::logAuth(false, [
                    'type' => 'jwt',
                    'route' => $route,
                    'required_scope' => $requiredScope,
                    'code' => 'rate_limited',
                    'message' => $message,
                    'kid' => $ctx->kid(),
                    'actor' => $ctx->actor(),
                ]);
                return new \WP_Error(
                    'rate_limited',
                    $message,
                    $errorData
                );
            }

            $bridgeUserId = (int) get_option(Installer::OPTION_BRIDGE_USER_ID, 0);
            if ($bridgeUserId > 0) {
                wp_set_current_user($bridgeUserId);
            }

            McpLogger::logAuth(true, [
                'type' => 'jwt',
                'route' => $route,
                'rel_path' => $relPath,
                'method' => $request->get_method(),
                'required_scope' => $requiredScope,
                'kid' => $ctx->kid(),
                'iss' => $ctx->iss(),
                'actor' => $ctx->actor(),
                'actor_name' => $ctx->actorName(),
                'session' => $ctx->session(),
                'scopes' => $ctx->scopes(),
                'warnings' => $ctx->warnings(),
            ]);

            return true;
        };
    }

    /**
     * Normalize `htu` according to Plugin §6.3:
     * REST route path relative to `/wp-json/ln-builder/v1` (with leading slash, no trailing slash except root),
     * plus query string with keys sorted (`PHP_QUERY_RFC3986`), excluding `rest_route`.
     *
     * @param array<string, mixed> $queryParams
     */
    public static function normalizeHtu(string $routeRelativePath, array $queryParams = []): string
    {
        $path = '/' . ltrim($routeRelativePath, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        unset($queryParams['rest_route']);
        if ($queryParams === []) {
            return $path;
        }

        self::ksortRecursive($queryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        return $queryString !== '' ? $path . '?' . $queryString : $path;
    }

    /**
     * Extract Authorization header from WP_REST_Request or server variables (including Apache/FastCGI fallback).
     */
    public static function extractAuthorizationHeader(?\WP_REST_Request $request = null): ?string
    {
        if ($request !== null) {
            $header = $request->get_header('authorization');
            if (is_string($header) && trim($header) !== '') {
                return trim($header);
            }
        }

        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strtolower((string) $k) === 'authorization' && is_string($v) && trim($v) !== '') {
                        return trim($v);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Insert JTI into `{prefix}ln_builder_jti` and opportunistically purge up to 100 expired rows.
     */
    public static function recordJtiInDb(string $jti, string $kid, int $expiresAtTimestamp): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ln_builder_jti';
        $nowUtc = gmdate('Y-m-d H:i:s');
        $expiresUtc = gmdate('Y-m-d H:i:s', $expiresAtTimestamp);

        // Opportunistic cleanup of expired JTIs
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s LIMIT 100", $nowUtc));

        // Suppress duplicate key errors on insert
        $suppress = $wpdb->suppress_errors(true);
        try {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (jti, kid, expires_at) VALUES (%s, %s, %s)",
                $jti,
                $kid,
                $expiresUtc
            ));
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        return $inserted === 1;
    }

    /**
     * Authenticate a Dev Mode Basic Application Password request (bridge user ONLY).
     *
     * @return bool|\WP_Error
     */
    private static function authenticateDevRequest(string $authHeader, string $requiredScope)
    {
        $encoded = trim(substr(trim($authHeader), 6));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return new \WP_Error('token_invalid', 'Malformed Basic credentials.', ['status' => 401]);
        }

        [$username, $password] = explode(':', $decoded, 2);
        $bridgeUserId = (int) get_option(Installer::OPTION_BRIDGE_USER_ID, 0);

        if ($bridgeUserId <= 0 || $username !== Installer::USER_LOGIN) {
            return new \WP_Error(
                'token_invalid',
                'Dev auth only accepts Application Passwords for the dedicated connector user.',
                ['status' => 401]
            );
        }

        $user = wp_authenticate_application_password(null, $username, $password);
        if (!($user instanceof \WP_User) || (int) $user->ID !== $bridgeUserId) {
            return new \WP_Error('token_invalid', 'Invalid Application Password for bridge user.', ['status' => 401]);
        }

        $allScopes = KeyRegistry::ALLOWED_SCOPES;
        if ($requiredScope !== '' && !in_array($requiredScope, $allScopes, true)) {
            return new \WP_Error('scope_denied', sprintf("Missing required scope '%s'.", $requiredScope), ['status' => 403]);
        }

        wp_set_current_user($bridgeUserId);
        $ctx = new RequestContext(
            RequestContext::currentRequestId(),
            'dev',
            'ln-builder-dev',
            $user->user_login,
            'dev-session',
            $allScopes,
            true
        );
        RequestContext::setCurrent($ctx);

        return true;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function ksortRecursive(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$v) {
            if (is_array($v) && !array_is_list($v)) {
                self::ksortRecursive($v);
            }
        }
    }

    /**
     * @return array{ok: false, status: int, code: string, message: string}
     */
    private function fail(int $status, string $code, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];
    }
}
