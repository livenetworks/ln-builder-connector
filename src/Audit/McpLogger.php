<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Audit;

use LiveNetworks\BuilderConnector\Auth\RequestContext;
use LiveNetworks\BuilderConnector\Converter\ExtraCodec;

final class McpLogger
{
    public const LOG_FILE_NAME = 'mcp-debug.log';
    private const MAX_BODY_LOG_BYTES = 8192; // 8 KB limit for logging body payloads when debug mode is enabled
    private const MAX_LOG_FILE_SIZE = 5242880; // 5 MB max file size before rotation

    /**
     * Map of request ID => start float microtime
     *
     * @var array<string, float>
     */
    private static array $startTimes = [];

    /**
     * Set of request IDs that already had response logged to prevent duplicate logging
     *
     * @var array<string, bool>
     */
    private static array $loggedResponses = [];

    /**
     * Set of auth log keys to prevent duplicate auth logging within the same request lifecycle
     *
     * @var array<string, bool>
     */
    private static array $loggedAuth = [];

    /**
     * Debug logging is strictly opt-in and disabled by default and disabled in production.
     */
    public static function isEnabled(): bool
    {
        return defined('LN_BUILDER_MCP_DEBUG')
            && LN_BUILDER_MCP_DEBUG === true
            && (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'production');
    }

    public static function getLogFilePath(): string
    {
        $uploadDir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        $baseDir = ($uploadDir && !empty($uploadDir['basedir']))
            ? $uploadDir['basedir'] . '/ln-builder-logs'
            : (defined('LN_BUILDER_PLUGIN_DIR') ? LN_BUILDER_PLUGIN_DIR : dirname(__DIR__, 2));

        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0750, true);
            @file_put_contents($baseDir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($baseDir . '/index.php', "<?php // Silence is golden\n");
        }

        return $baseDir . '/' . self::LOG_FILE_NAME;
    }

    /**
     * Write raw message to the log file (only when enabled).
     */
    public static function write(string $message): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $file = self::getLogFilePath();

        // Rotate if file exceeds 5MB
        if (file_exists($file) && filesize($file) > self::MAX_LOG_FILE_SIZE) {
            @rename($file, $file . '.old');
        }

        @file_put_contents($file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log an incoming request to /ln-builder/v1/* (debug mode only).
     */
    public static function logRequest(\WP_REST_Request $request): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s \U\T\C');
        $requestId = self::resolveRequestId($request);
        self::$startTimes[$requestId] = microtime(true);

        $method = strtoupper((string) $request->get_method());
        $route = (string) $request->get_route();
        $ip = self::getClientIp();
        $ua = (string) ($request->get_header('user_agent') ?? 'unknown');

        $headers = self::extractRelevantHeaders($request);
        $queryParams = $request->get_query_params();
        $rawBody = (string) $request->get_body();

        // Check for safe JWT preview without leaking sensitive tokens
        $authHeader = $request->get_header('authorization');
        $jwtPreview = self::decodeJwtForDebug($authHeader);

        $lines = [];
        $lines[] = '================================================================================';
        $lines[] = sprintf('[%s] [%s] >>> INCOMING REQUEST: %s %s', $now, $requestId, $method, $route);
        $lines[] = sprintf('Client IP: %s | User-Agent: %s', $ip, $ua);

        if ($headers !== []) {
            $lines[] = 'Headers:';
            foreach ($headers as $k => $v) {
                $lines[] = sprintf('  %s: %s', $k, $v);
            }
        }

        if ($queryParams !== []) {
            $lines[] = 'Query Parameters:';
            $lines[] = '  ' . wp_json_encode($queryParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($jwtPreview !== null) {
            $lines[] = 'Decoded JWT Token Preview:';
            $lines[] = '  Header: ' . wp_json_encode($jwtPreview['header'], JSON_UNESCAPED_SLASHES);
            $lines[] = '  Claims: ' . wp_json_encode($jwtPreview['claims'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($rawBody !== '') {
            $lines[] = 'Body:';
            $bodyLen = strlen($rawBody);
            if ($bodyLen > self::MAX_BODY_LOG_BYTES) {
                $lines[] = sprintf('  [Truncated: %d bytes total; showing first %d bytes]', $bodyLen, self::MAX_BODY_LOG_BYTES);
                $preview = substr($rawBody, 0, self::MAX_BODY_LOG_BYTES);
                $lines[] = '  ' . $preview;
            } else {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $lines[] = '  ' . wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $lines[] = '  ' . $rawBody;
                }
            }
        } else {
            $lines[] = 'Body: (empty)';
        }

        self::write(implode(PHP_EOL, $lines));
    }

    /**
     * Log the result of authentication / permission check (debug mode only).
     *
     * @param array<string, mixed> $context
     */
    public static function logAuth(bool $ok, array $context): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s \U\T\C');
        $requestId = RequestContext::currentRequestId();

        $dedupKey = $requestId . ':' . ($ok ? '1' : '0') . ':' . ($context['code'] ?? '') . ':' . ($context['required_scope'] ?? '');
        if (isset(self::$loggedAuth[$dedupKey])) {
            return;
        }
        self::$loggedAuth[$dedupKey] = true;

        $lines = [];
        $lines[] = '--------------------------------------------------------------------------------';
        if ($ok) {
            $lines[] = sprintf('[%s] [%s] AUTH RESULT: SUCCESS', $now, $requestId);
            if (isset($context['type'])) {
                $lines[] = sprintf('Auth Type: %s', $context['type']);
            }
            if (isset($context['kid'])) {
                $lines[] = sprintf('Key ID: %s | Iss: %s', (string) $context['kid'], (string) ($context['iss'] ?? ''));
            }
            if (isset($context['actor'])) {
                $actorStr = (string) $context['actor'];
                if (!empty($context['actor_name'])) {
                    $actorStr .= sprintf(' (%s)', (string) $context['actor_name']);
                }
                $lines[] = sprintf('Actor: %s | Session: %s', $actorStr, (string) ($context['session'] ?? ''));
            }
            if (isset($context['scopes'])) {
                $lines[] = sprintf(
                    'Granted Scopes: [%s] | Required: %s',
                    implode(', ', (array) $context['scopes']),
                    (string) ($context['required_scope'] ?? 'none')
                );
            }
            if (!empty($context['warnings'])) {
                $lines[] = 'Warnings: ' . wp_json_encode($context['warnings'], JSON_UNESCAPED_SLASHES);
            }
        } else {
            $lines[] = sprintf('[%s] [%s] AUTH RESULT: FAILED', $now, $requestId);
            $lines[] = sprintf(
                'Status: %d | Code: %s | Message: %s',
                (int) ($context['status'] ?? 401),
                (string) ($context['code'] ?? 'unauthorized'),
                (string) ($context['message'] ?? 'Authentication failed')
            );
            if (isset($context['required_scope'])) {
                $lines[] = sprintf('Required Scope: %s', (string) $context['required_scope']);
            }
            if (isset($context['route'])) {
                $lines[] = sprintf('Route: %s (Method: %s)', (string) $context['route'], (string) ($context['method'] ?? ''));
            }

            if (!empty($context['details'])) {
                $lines[] = '  Error Details: ' . wp_json_encode($context['details'], JSON_UNESCAPED_SLASHES);
            }
        }

        self::write(implode(PHP_EOL, $lines));
    }

    /**
     * Log an outgoing REST response (debug mode only).
     *
     * @param mixed $response \WP_REST_Response|\WP_Error|array|null
     */
    public static function logResponse($response, ?\WP_REST_Request $request = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s \U\T\C');
        $requestId = $request !== null ? self::resolveRequestId($request) : RequestContext::currentRequestId();

        if (isset(self::$loggedResponses[$requestId])) {
            return;
        }
        self::$loggedResponses[$requestId] = true;

        $durationMs = 0.0;
        if (isset(self::$startTimes[$requestId])) {
            $durationMs = round((microtime(true) - self::$startTimes[$requestId]) * 1000, 2);
            unset(self::$startTimes[$requestId]);
        }

        $statusCode = 200;
        $headers = [];
        $data = null;

        if ($response instanceof \WP_REST_Response) {
            $statusCode = $response->get_status();
            $headers = $response->get_headers();
            $data = $response->get_data();
        } elseif ($response instanceof \WP_Error) {
            $errData = $response->get_error_data();
            $statusCode = (is_array($errData) && isset($errData['status'])) ? (int) $errData['status'] : 400;
            $data = [
                'error' => [
                    'code' => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                    'data' => $errData,
                ],
            ];
        } elseif (is_array($response)) {
            $data = $response;
        }

        $lines = [];
        $lines[] = sprintf('[%s] [%s] <<< OUTGOING RESPONSE: HTTP %d (duration: %s ms)', $now, $requestId, $statusCode, $durationMs > 0 ? (string) $durationMs : '<1');

        if ($headers !== []) {
            $lines[] = 'Response Headers:';
            foreach ($headers as $k => $v) {
                if (in_array(strtolower((string) $k), ['content-type', 'x-request-id', 'retry-after', 'x-content-type-options'], true)) {
                    $lines[] = sprintf('  %s: %s', $k, is_array($v) ? implode(', ', $v) : (string) $v);
                }
            }
        }

        if ($data !== null) {
            $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                if (strlen($encoded) > self::MAX_BODY_LOG_BYTES) {
                    $lines[] = sprintf('Response Data: [Truncated: %d bytes total; showing first %d bytes]', strlen($encoded), self::MAX_BODY_LOG_BYTES);
                    $lines[] = substr($encoded, 0, self::MAX_BODY_LOG_BYTES);
                } else {
                    $lines[] = 'Response Data:';
                    $lines[] = $encoded;
                }
            }
        }

        $lines[] = '================================================================================';

        self::write(implode(PHP_EOL, $lines));
    }

    /**
     * Resolve request ID from request header, query, or active context.
     */
    private static function resolveRequestId(?\WP_REST_Request $request): string
    {
        if ($request !== null) {
            $fromHeader = $request->get_header('x-request-id');
            if (is_string($fromHeader) && $fromHeader !== '') {
                return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $fromHeader) ?: 'req-unknown', 0, 64);
            }
        }

        $fromCtx = RequestContext::currentRequestId();
        if ($fromCtx !== 'req-unknown') {
            return $fromCtx;
        }

        return 'req-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Extract relevant non-sensitive headers for request logging.
     *
     * @return array<string, string>
     */
    private static function extractRelevantHeaders(\WP_REST_Request $request): array
    {
        $safeHeaders = [
            'content-type',
            'accept',
            'x-request-id',
            'x-idempotency-key',
            'x-content-digest',
            'user-agent',
            'host',
        ];

        $out = [];
        foreach ($safeHeaders as $h) {
            $val = $request->get_header($h);
            if (is_string($val) && $val !== '') {
                $out[$h] = $val;
            }
        }

        if ($request->get_header('authorization')) {
            $out['authorization'] = '[REDACTED]';
        }

        return $out;
    }

    /**
     * Get real client IP.
     */
    private static function getClientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * Decode JWT structure for debug inspection (without validating signature).
     *
     * @return array{header: array<string, mixed>, claims: array<string, mixed>}|null
     */
    private static function decodeJwtForDebug(?string $authHeader): ?array
    {
        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $headerRaw = ExtraCodec::base64UrlDecode($parts[0]);
        $claimsRaw = ExtraCodec::base64UrlDecode($parts[1]);

        if ($headerRaw === null || $claimsRaw === null) {
            return null;
        }

        $header = json_decode($headerRaw, true);
        $claims = json_decode($claimsRaw, true);

        if (!is_array($header) || !is_array($claims)) {
            return null;
        }

        // Redact any sensitive internal data from logged claims
        return [
            'header' => $header,
            'claims' => $claims,
        ];
    }
}
