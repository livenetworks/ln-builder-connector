<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Connect;

use LiveNetworks\BuilderConnector\Audit\AuditLogger;
use LiveNetworks\BuilderConnector\Auth\JwksClient;
use LiveNetworks\BuilderConnector\Auth\KeyRegistry;
use LiveNetworks\BuilderConnector\Builders\Detector;
use LiveNetworks\BuilderConnector\Converter\ExtraCodec;
use LiveNetworks\BuilderConnector\Install\Installer;

final class ConnectClient
{
    public const STATE_TRANSIENT_PREFIX = '_ln_builder_conn_state_';
    public const STATE_USER_PREFIX = '_ln_builder_conn_user_';
    public const STATE_EXPIRY_SECONDS = 600; // 10 minutes

    /**
     * Generate connect redirect URL and record state transient.
     */
    public static function buildConnectUrl(int $wpUserId): string
    {
        $siteId = (string) get_option(Installer::OPTION_SITE_ID, '');
        $panelUrl = PlatformState::panelUrl();

        $randomBytes = random_bytes(32);
        $state = ExtraCodec::base64UrlEncode($randomBytes);
        $stateHash = hash('sha256', $state);

        set_transient(self::STATE_TRANSIENT_PREFIX . $wpUserId, $stateHash, self::STATE_EXPIRY_SECONDS);
        set_transient(self::STATE_USER_PREFIX . $stateHash, $wpUserId, self::STATE_EXPIRY_SECONDS);

        PlatformState::set([
            'status' => PlatformState::STATUS_CONNECTING,
            'last_error' => null,
        ]);

        $detected = Detector::detect();
        $builder = $detected['builder'] !== 'none' ? $detected['builder'] : 'bricks';

        $returnUrl = admin_url('admin.php?page=ln-builder&connect=return');
        $callbackUrl = rest_url('ln-builder/v1/connect/confirm');

        $params = [
            'state' => $state,
            'site_uuid' => $siteId,
            'url' => home_url(),
            'name' => get_bloginfo('name'),
            'builder' => $builder,
            'plugin_version' => LN_BUILDER_VERSION,
            'contract' => LN_BUILDER_CONTRACT_VERSION,
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
        ];

        return $panelUrl . '/connect?' . http_build_query($params);
    }

    /**
     * Handle the return callback from the panel and exchange the code.
     *
     * @return array{success: bool, status?: string, code?: string, error?: string}
     */
    public static function handleReturn(string $code, string $state, int $wpUserId): array
    {
        $stateHash = hash('sha256', $state);
        if ($wpUserId <= 0) {
            $storedUserId = get_transient(self::STATE_USER_PREFIX . $stateHash);
            if (is_numeric($storedUserId) && (int) $storedUserId > 0) {
                $wpUserId = (int) $storedUserId;
            }
        }

        $transientKey = self::STATE_TRANSIENT_PREFIX . $wpUserId;
        $storedHash = get_transient($transientKey);
        delete_transient($transientKey);
        delete_transient(self::STATE_USER_PREFIX . $stateHash);

        if (!is_string($storedHash) || $storedHash === '' || !hash_equals($storedHash, $stateHash)) {
            return [
                'success' => false,
                'code' => 'state_mismatch',
                'error' => __('Invalid or expired connect state. Please try connecting again.', 'ln-builder-connector'),
            ];
        }

        $siteId = (string) get_option(Installer::OPTION_SITE_ID, '');
        $panelUrl = PlatformState::panelUrl();
        $exchangeUrl = $panelUrl . '/api/connect/exchange';
        $exchangePayload = [
            'code' => $code,
            'state' => $state,
            'site_uuid' => $siteId,
        ];

        $response = wp_remote_post($exchangeUrl, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => (string) json_encode($exchangePayload),
        ]);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            PlatformState::set(['last_error' => $msg]);
            return [
                'success' => false,
                'code' => 'network_error',
                'error' => sprintf(__('Connection failed: %s', 'ln-builder-connector'), $msg),
            ];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $body = trim($rawBody);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $errorMsg = sprintf(
                __('Exchange failed with HTTP %d: invalid JSON response (%s)', 'ln-builder-connector'),
                $httpCode,
                substr($rawBody, 0, 120)
            );
            PlatformState::set(['last_error' => $errorMsg]);
            return [
                'success' => false,
                'code' => 'exchange_failed',
                'error' => $errorMsg,
            ];
        }

        // Determine whether response indicates failure even if HTTP 200
        $hasError = ($httpCode < 200 || $httpCode >= 300)
            || (isset($data['success']) && ($data['success'] === false || $data['success'] === 'false' || $data['success'] === 0))
            || !empty($data['error']);

        if ($hasError) {
            $errorCode = 'exchange_failed';
            if (!empty($data['error'])) {
                if (is_string($data['error'])) {
                    $errorCode = $data['error'];
                } elseif (is_array($data['error']) && !empty($data['error']['code'])) {
                    $errorCode = (string) $data['error']['code'];
                }
            } elseif (!empty($data['code'])) {
                $errorCode = (string) $data['code'];
            }

            $errorMsg = '';
            if (!empty($data['message']) && is_string($data['message'])) {
                $errorMsg = $data['message'];
            } elseif (is_array($data['error'] ?? null) && !empty($data['error']['message'])) {
                $errorMsg = (string) $data['error']['message'];
            } elseif (is_string($data['error'] ?? null) && empty($data['message'])) {
                $errorMsg = (string) $data['error'];
            }

            if ($errorMsg === '') {
                $errorMsg = sprintf(__('Exchange failed with HTTP %d', 'ln-builder-connector'), $httpCode);
            }

            PlatformState::set(['last_error' => $errorMsg]);
            return [
                'success' => false,
                'code' => (string) $errorCode,
                'error' => (string) $errorMsg,
            ];
        }

        // Extract payload: accept nested in 'data', stringified JSON in 'data', 'result', or flat at root
        $exchangeData = null;
        if (isset($data['data']) && is_array($data['data'])) {
            $exchangeData = $data['data'];
        } elseif (isset($data['data']) && is_string($data['data'])) {
            $decoded = json_decode($data['data'], true);
            if (is_array($decoded)) {
                $exchangeData = $decoded;
            }
        } elseif (isset($data['result']) && is_array($data['result'])) {
            $exchangeData = $data['result'];
        } elseif (
            isset($data['site_key']) ||
            isset($data['site_id']) ||
            isset($data['site_uuid']) ||
            isset($data['jwks_uri'])
        ) {
            $exchangeData = $data;
        }

        if (!is_array($exchangeData) || empty($exchangeData)) {
            $errorMsg = sprintf(__('Exchange failed with HTTP %d: missing data in response', 'ln-builder-connector'), $httpCode);
            PlatformState::set(['last_error' => $errorMsg]);
            return [
                'success' => false,
                'code' => 'exchange_failed',
                'error' => $errorMsg,
            ];
        }

        // Field extraction with fallbacks across Contract 0.4.0 variations
        $siteKey = (string) ($exchangeData['site_key'] ?? $exchangeData['site_id'] ?? $exchangeData['site_uuid'] ?? '');
        $siteUuid = (string) ($exchangeData['site_uuid'] ?? $exchangeData['site_id'] ?? '');
        $iss = (string) ($exchangeData['iss'] ?? $exchangeData['issuer'] ?? '');
        $jwksUri = (string) ($exchangeData['jwks_uri'] ?? $exchangeData['jwksUri'] ?? $exchangeData['jwks_url'] ?? '');
        $apiStatus = (string) ($exchangeData['status'] ?? 'active');

        // Scopes normalisation (array or space/comma separated string)
        $scopes = [];
        if (isset($exchangeData['scopes'])) {
            if (is_array($exchangeData['scopes'])) {
                $scopes = array_values(array_filter(array_map('strval', $exchangeData['scopes'])));
            } elseif (is_string($exchangeData['scopes'])) {
                $scopes = preg_split('/[\s,]+/', trim($exchangeData['scopes'])) ?: [];
            }
        }
        if (empty($scopes)) {
            $scopes = ['read', 'content:write', 'publish', 'design:write'];
        }

        if ($siteKey === '' || $iss === '' || $jwksUri === '') {
            $msg = __('Panel response missing required fields (site_key, iss, or jwks_uri).', 'ln-builder-connector');
            PlatformState::set(['last_error' => $msg]);
            return [
                'success' => false,
                'code' => 'invalid_response',
                'error' => $msg,
            ];
        }

        // Optional legacy/hybrid public key support (Contract 0.4.0 uses JWKS, public_key is not required)
        if (!empty($exchangeData['public_key']) && is_string($exchangeData['public_key'])) {
            KeyRegistry::addKey(
                (string) ($exchangeData['kid'] ?? 'panel-key'),
                $iss,
                $exchangeData['public_key'],
                $scopes
            );
        }

        // Fetch JWKS keys before confirming connection
        $jwksRes = JwksClient::refresh($jwksUri);
        if (!empty($jwksRes['uri']) && is_string($jwksRes['uri'])) {
            $jwksUri = $jwksRes['uri'];
        }

        if (!$jwksRes['success'] || $jwksRes['count'] < 1) {
            $msg = sprintf(
                __('Could not load public keys from JWKS: %s', 'ln-builder-connector'),
                $jwksRes['error'] ?? __('No valid Ed25519 keys found', 'ln-builder-connector')
            );
            PlatformState::set(['last_error' => $msg]);
            return [
                'success' => false,
                'code' => 'jwks_fetch_failed',
                'error' => $msg,
            ];
        }

        $newStatus = ($apiStatus === 'pending_approval')
            ? PlatformState::STATUS_PENDING_APPROVAL
            : PlatformState::STATUS_CONNECTED;

        PlatformState::set([
            'status' => $newStatus,
            'site_key' => $siteKey,
            'iss' => $iss,
            'jwks_uri' => $jwksUri,
            'granted_scopes' => $scopes,
            'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'connected_by' => $wpUserId > 0 ? $wpUserId : null,
            'connected_url' => home_url(),
            'last_error' => null,
        ]);

        // Content owner is the WP user who completed Connect
        if ($wpUserId > 0) {
            update_option(Installer::OPTION_CONTENT_OWNER, $wpUserId, false);
        }

        AuditLogger::log(
            'POST /connect/exchange',
            'connect',
            $siteKey,
            null,
            'ok',
            [
                'status' => $newStatus,
                'iss' => $iss,
                'jwks_uri' => $jwksUri,
                'scopes' => $scopes,
            ],
            'wp:' . $wpUserId,
            null,
            'connect-flow',
            ''
        );

        return [
            'success' => true,
            'status' => $newStatus,
        ];
    }

    /**
     * Disconnect the site from the MCP panel.
     */
    public static function disconnect(int $wpUserId = 0): void
    {
        $siteId = (string) get_option(Installer::OPTION_SITE_ID, '');
        $siteKey = PlatformState::siteKey();
        $panelUrl = PlatformState::panelUrl();

        // 1. Clear local state and keys immediately
        PlatformState::clear();
        JwksClient::clearCache();
        update_option(KeyRegistry::OPTION_KEYS, [], true);

        // 2. Audit disconnect event
        AuditLogger::log(
            'POST /connect/disconnect',
            'connect',
            $siteKey !== '' ? $siteKey : 'unknown',
            null,
            'ok',
            ['site_uuid' => $siteId],
            $wpUserId > 0 ? 'wp:' . $wpUserId : 'system',
            null,
            'disconnect',
            ''
        );

        // 3. Best effort: notify panel
        if ($siteKey !== '' && $panelUrl !== '') {
            wp_remote_post($panelUrl . '/api/connect/disconnected', [
                'timeout' => 5,
                'blocking' => false, // async fire & forget
                'headers' => ['Content-Type' => 'application/json'],
                'body' => (string) json_encode([
                    'site_uuid' => $siteId,
                    'site_key' => $siteKey,
                ]),
            ]);
        }
    }

    /**
     * Reset site registration: clears platform state, JWKS cache, transients,
     * and optionally generates a new Site UUID.
     */
    public static function resetRegistration(int $wpUserId = 0, bool $regenerateSiteId = false): void
    {
        $siteId = (string) get_option(Installer::OPTION_SITE_ID, '');

        // 1. Disconnect and clear platform state + JWKS
        self::disconnect($wpUserId);

        // 2. Clear state transient for this user
        if ($wpUserId > 0) {
            delete_transient(self::STATE_TRANSIENT_PREFIX . $wpUserId);
        }

        // 3. Clear any current user transient
        if (function_exists('get_current_user_id')) {
            $currId = get_current_user_id();
            if ($currId > 0 && $currId !== $wpUserId) {
                delete_transient(self::STATE_TRANSIENT_PREFIX . $currId);
            }
        }

        // 4. Optionally regenerate site UUID
        if ($regenerateSiteId && function_exists('wp_generate_uuid4')) {
            $siteId = wp_generate_uuid4();
            update_option(Installer::OPTION_SITE_ID, $siteId, true);
        }

        // 5. Audit reset event
        AuditLogger::log(
            'POST /connect/reset',
            'connect',
            'unknown',
            null,
            'ok',
            [
                'site_uuid' => $siteId,
                'regenerated_uuid' => $regenerateSiteId,
            ],
            $wpUserId > 0 ? 'wp:' . $wpUserId : 'system',
            null,
            'reset-registration',
            ''
        );
    }

    /**
     * Handle direct webhook confirmation from the panel (Server-to-Server).
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, status?: string, code?: string, error?: string}
     */
    public static function handleDirectConfirmation(array $payload): array
    {
        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;

        $state = (string) ($payload['state'] ?? $data['state'] ?? '');
        $siteUuid = (string) ($payload['site_uuid'] ?? $data['site_uuid'] ?? $payload['site_id'] ?? $data['site_id'] ?? '');

        if ($siteUuid === '') {
            return [
                'success' => false,
                'code' => 'invalid_request',
                'error' => __('Missing required site_uuid parameter.', 'ln-builder-connector'),
            ];
        }

        $expectedSiteId = (string) get_option(Installer::OPTION_SITE_ID, '');
        if ($expectedSiteId === '' || !hash_equals($expectedSiteId, $siteUuid)) {
            return [
                'success' => false,
                'code' => 'site_mismatch',
                'error' => __('Site UUID mismatch.', 'ln-builder-connector'),
            ];
        }

        $stateHash = $state !== '' ? hash('sha256', $state) : '';
        $storedUserId = $stateHash !== '' ? get_transient(self::STATE_USER_PREFIX . $stateHash) : false;
        $wpUserId = (is_numeric($storedUserId) && (int) $storedUserId > 0) ? (int) $storedUserId : 0;

        // If site_key is NOT provided, but code is, use standard handleReturn to exchange with panel
        $siteKeyCandidate = (string) ($payload['site_key'] ?? $data['site_key'] ?? '');
        $code = (string) ($payload['code'] ?? $data['code'] ?? '');
        if ($siteKeyCandidate === '' && $code !== '') {
            if ($state === '') {
                return [
                    'success' => false,
                    'code' => 'invalid_request',
                    'error' => __('Missing required state parameter.', 'ln-builder-connector'),
                ];
            }
            return self::handleReturn($code, $state, $wpUserId);
        }

        // Direct key confirmation requires an active connecting flow
        if (PlatformState::status() !== PlatformState::STATUS_CONNECTING && PlatformState::status() !== PlatformState::STATUS_PENDING_APPROVAL) {
            return [
                'success' => false,
                'code' => 'not_connecting',
                'error' => __('No connect flow in progress.', 'ln-builder-connector'),
            ];
        }

        // Mandatory state parameter to prevent unauthenticated takeover
        if ($state === '') {
            return [
                'success' => false,
                'code' => 'invalid_request',
                'error' => __('Missing required state parameter.', 'ln-builder-connector'),
            ];
        }

        // Validate state transient
        $transientKey = self::STATE_TRANSIENT_PREFIX . $wpUserId;
        $storedHash = get_transient($transientKey);
        delete_transient($transientKey);
        delete_transient(self::STATE_USER_PREFIX . $stateHash);

        if (!is_string($storedHash) || $storedHash === '' || !hash_equals($storedHash, $stateHash)) {
            return [
                'success' => false,
                'code' => 'state_mismatch',
                'error' => __('Invalid or expired connect state.', 'ln-builder-connector'),
            ];
        }

        // Direct key confirmation
        $siteKey = (string) ($payload['site_key'] ?? $data['site_key'] ?? $data['site_id'] ?? $data['site_uuid'] ?? '');
        $iss = (string) ($payload['iss'] ?? $data['iss'] ?? $data['issuer'] ?? '');
        $jwksUri = (string) ($payload['jwks_uri'] ?? $data['jwks_uri'] ?? $data['jwks_url'] ?? '');

        $rawScopes = $payload['scopes'] ?? $data['scopes'] ?? [];
        $scopes = [];
        if (is_array($rawScopes)) {
            $scopes = array_values(array_filter(array_map('strval', $rawScopes)));
        } elseif (is_string($rawScopes)) {
            $scopes = preg_split('/[\s,]+/', trim($rawScopes)) ?: [];
        }
        $scopes = array_values(array_intersect(KeyRegistry::ALLOWED_SCOPES, $scopes));
        if (empty($scopes)) {
            $scopes = ['read', 'content:write', 'publish', 'design:write'];
        }

        $apiStatus = (string) ($payload['status'] ?? $data['status'] ?? 'active');

        if ($siteKey === '' || $iss === '' || $jwksUri === '') {
            return [
                'success' => false,
                'code' => 'invalid_payload',
                'error' => __('Payload missing required fields (site_key, iss, or jwks_uri).', 'ln-builder-connector'),
            ];
        }

        // Enforce SSRF protection on jwks_uri
        if (!JwksClient::isTrustedJwksUri($jwksUri)) {
            return [
                'success' => false,
                'code' => 'untrusted_jwks_uri',
                'error' => __('Untrusted JWKS URI.', 'ln-builder-connector'),
            ];
        }

        // Fetch JWKS keys before confirming connection
        $jwksRes = JwksClient::refresh($jwksUri);
        if (!empty($jwksRes['uri']) && is_string($jwksRes['uri'])) {
            $jwksUri = $jwksRes['uri'];
        }
        if (!$jwksRes['success'] || $jwksRes['count'] < 1) {
            $msg = sprintf(
                __('Could not load public keys from JWKS: %s', 'ln-builder-connector'),
                $jwksRes['error'] ?? __('No valid Ed25519 keys found', 'ln-builder-connector')
            );
            PlatformState::set(['last_error' => $msg]);
            return [
                'success' => false,
                'code' => 'jwks_fetch_failed',
                'error' => $msg,
            ];
        }

        $newStatus = ($apiStatus === 'pending_approval')
            ? PlatformState::STATUS_PENDING_APPROVAL
            : PlatformState::STATUS_CONNECTED;

        PlatformState::set([
            'status' => $newStatus,
            'site_key' => $siteKey,
            'iss' => $iss,
            'jwks_uri' => $jwksUri,
            'granted_scopes' => $scopes,
            'connected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'connected_by' => $wpUserId > 0 ? $wpUserId : null,
            'connected_url' => home_url(),
            'last_error' => null,
        ]);

        if ($wpUserId > 0) {
            update_option(Installer::OPTION_CONTENT_OWNER, $wpUserId, false);
        }

        AuditLogger::log(
            'POST /connect/confirm',
            'connect',
            $siteKey,
            null,
            'ok',
            [
                'status' => $newStatus,
                'iss' => $iss,
                'jwks_uri' => $jwksUri,
                'scopes' => $scopes,
            ],
            $wpUserId > 0 ? 'wp:' . $wpUserId : 'panel:webhook',
            null,
            'connect-flow',
            ''
        );

        return [
            'success' => true,
            'status' => $newStatus,
        ];
    }
}
