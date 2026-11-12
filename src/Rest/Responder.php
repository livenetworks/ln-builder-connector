<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Rest;

use LiveNetworks\BuilderConnector\Audit\McpLogger;
use LiveNetworks\BuilderConnector\Auth\RequestContext;
use LiveNetworks\BuilderConnector\Auth\SecurityGuard;
use LiveNetworks\BuilderConnector\Builders\Detector;
use LiveNetworks\BuilderConnector\Converter\Problem;

final class Responder
{
    public const NAMESPACE = 'ln-builder/v1';
    public const MAX_BODY_BYTES = 2097152; // 2 MB

    private const FORBIDDEN_CONTROL_PARAMS = ['_method', '_envelope', '_jsonp', '_fields', '_embed'];

    public static function registerHooks(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'afterCallbacks'], 99, 3);
        add_filter('rest_post_dispatch', [self::class, 'postDispatch'], 99, 3);
    }

    /**
     * Build a standard Contract §3.1 success response.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extraMeta
     */
    public static function success(array $data, int $status = 200, array $extraMeta = []): \WP_REST_Response
    {
        $meta = array_merge([
            'contract' => LN_BUILDER_CONTRACT_VERSION,
        ], $extraMeta);

        $response = new \WP_REST_Response([
            'data' => $data,
            'meta' => $meta,
        ], $status);

        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Build a standard Contract §3.1 error response.
     *
     * @param array<int, array<string, mixed>>|Problem[] $details
     * @param array<string, mixed> $extraMeta
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $extraMeta = []
    ): \WP_REST_Response {
        $normalizedDetails = [];
        if ($details !== [] && !array_is_list($details)) {
            $normalizedDetails = $details;
        } else {
            foreach ($details as $item) {
                if ($item instanceof Problem) {
                    $normalizedDetails[] = $item->toArray();
                } elseif (is_array($item)) {
                    $normalizedDetails[] = $item;
                }
            }
        }

        $errorPayload = [
            'code' => $code,
            'message' => $message,
        ];
        if ($normalizedDetails !== []) {
            $errorPayload['details'] = $normalizedDetails;
        }

        $meta = array_merge([
            'contract' => LN_BUILDER_CONTRACT_VERSION,
            'request_id' => RequestContext::currentRequestId(),
        ], $extraMeta);

        $response = new \WP_REST_Response([
            'error' => $errorPayload,
            'meta' => $meta,
        ], $status);

        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Build a 422 validation_failed response from Problem[] errors and optional warnings.
     *
     * @param Problem[]|array<int, array<string, mixed>> $errors
     * @param Problem[] $warnings
     */
    public static function validationFailed(array $errors, array $warnings = []): \WP_REST_Response
    {
        $count = count($errors);
        $message = $count === 1
            ? '1 problem found in the submitted payload.'
            : sprintf('%d problems found in the submitted payload.', $count);

        $extraMeta = [];
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return self::error('validation_failed', $message, 422, $errors, $extraMeta);
    }

    /**
     * Wrap a route callback with:
     * 1. Active builder check (`501 builder_unsupported` when $requireBuilder is true)
     * 2. Top-level Throwable guard (`500 internal_error` with correlation ID in `meta.request_id`)
     *
     * @param callable(\WP_REST_Request, ?\LiveNetworks\BuilderConnector\Builders\BuilderAdapter): \WP_REST_Response $handler
     * @return callable(\WP_REST_Request): \WP_REST_Response
     */
    public static function wrap(callable $handler, bool $requireBuilder = true): callable
    {
        return static function (\WP_REST_Request $request) use ($handler, $requireBuilder): \WP_REST_Response {
            try {
                $detected = Detector::detect();
                $adapter = $detected['adapter'];

                if ($requireBuilder && $adapter === null) {
                    $reason = (string) ($detected['reason'] ?? 'no_supported_builder_active');
                    return self::error(
                        'builder_unsupported',
                        sprintf('No supported page builder is active on this site (%s).', $reason),
                        501
                    );
                }

                return $handler($request, $adapter);
            } catch (\Throwable $e) {
                $requestId = RequestContext::currentRequestId();
                error_log(sprintf(
                    '[ln-builder-connector] [%s] Uncaught %s: %s in %s:%d',
                    $requestId,
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));

                // Not `write_failed`: this guard also covers read-only routes, and nothing was written or restored.
                return self::error(
                    'internal_error',
                    sprintf('An unexpected internal error occurred while processing the request (request_id %s).', $requestId),
                    500
                );
            }
        };
    }

    /**
     * Pre-dispatch guard for `/ln-builder/v1`:
     * - Clear any ambient cookie-authenticated WP user before permission_callback runs
     * - Reject WP control/override query parameters (`_method`, `_envelope`, `_fields`, `_embed`, `_jsonp`)
     * - Enforce 2 MB body size limit
     *
     * @param mixed $result
     * @return mixed
     */
    public static function preDispatch($result, \WP_REST_Server $server, \WP_REST_Request $request)
    {
        if (!self::isOurRoute($request->get_route())) {
            return $result;
        }

        // Log incoming request from MCP
        McpLogger::logRequest($request);

        // Ignore ambient WordPress cookie/nonce sessions on this namespace
        wp_set_current_user(0);
        RequestContext::setCurrent(null);

        // Enforce IP allowlist BEFORE JWT verification and JTI insertion
        $ipCheck = SecurityGuard::checkIpAllowlist($request);
        if (!$ipCheck['ok']) {
            $err = self::error(
                'ip_not_allowed',
                'Client IP address is not in the configured IP allowlist.',
                403
            );
            McpLogger::logResponse($err, $request);
            return $err;
        }

        // Enforce per-IP failed request limit
        $failIpCheck = SecurityGuard::checkFailedIpRateLimit($request);
        if (!$failIpCheck['ok']) {
            $err = self::error(
                'rate_limited',
                'Too many failed requests from this IP address.',
                429
            );
            $err->header('Retry-After', (string) ($failIpCheck['retry_after'] ?? 60));
            McpLogger::logResponse($err, $request);
            return $err;
        }

        // Enforce 2 MB max request body size before parsing/validating
        $rawBody = (string) $request->get_body();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $err = self::error(
                'limit_exceeded',
                sprintf('Request body exceeds the maximum allowed size of %d bytes.', self::MAX_BODY_BYTES),
                422
            );
            McpLogger::logResponse($err, $request);
            return $err;
        }

        // Reject method override headers and WP REST control parameters that could alter htm/htu or envelope
        $overrideHeader = $request->get_header('x_http_method_override');
        if (is_string($overrideHeader) && $overrideHeader !== '') {
            $err = self::error(
                'request_mismatch',
                'X-HTTP-Method-Override header is not permitted on ln-builder endpoints.',
                401
            );
            McpLogger::logResponse($err, $request);
            return $err;
        }

        $queryParams = $request->get_query_params();
        foreach (self::FORBIDDEN_CONTROL_PARAMS as $forbidden) {
            if (array_key_exists($forbidden, $queryParams) || isset($_GET[$forbidden]) || isset($_POST[$forbidden])) {
                $err = self::error(
                    'request_mismatch',
                    sprintf("Control parameter '%s' is not permitted on ln-builder endpoints.", $forbidden),
                    401
                );
                McpLogger::logResponse($err, $request);
                return $err;
            }
        }

        return $result;
    }

    /**
     * Filter right after permission_callback and route callback inside WP_REST_Server::dispatch().
     * Ensures WP_Error instances are converted to Contract §3.1 WP_REST_Response both in HTTP requests and rest_do_request().
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
     * @param array<string, mixed> $handler
     * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed
     */
    public static function afterCallbacks($response, array $handler, \WP_REST_Request $request)
    {
        if (!self::isOurRoute($request->get_route())) {
            return $response;
        }

        if (is_wp_error($response)) {
            return self::convertWpError($response);
        }

        return $response;
    }

    /**
     * Post-dispatch filter for `/ln-builder/v1`:
     * Ensure any WP_Error or raw WP error response (401/403/404/400) conforms to the Contract §3.1 envelope.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
     * @return \WP_REST_Response
     */
    public static function postDispatch($response, \WP_REST_Server $server, \WP_REST_Request $request)
    {
        if (!self::isOurRoute($request->get_route())) {
            return $response;
        }

        if (is_wp_error($response)) {
            $converted = self::convertWpError($response);
            McpLogger::logResponse($converted, $request);
            return $converted;
        }

        $restResponse = rest_ensure_response($response);
        $restResponse->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        $data = $restResponse->get_data();
        // Already wrapped in our contract envelope
        if (is_array($data) && isset($data['meta']['contract']) && (array_key_exists('data', $data) || array_key_exists('error', $data))) {
            McpLogger::logResponse($restResponse, $request);
            return $restResponse;
        }

        // Reshape WordPress built-in error responses (e.g. rest_no_route, rest_invalid_json, etc.)
        if (is_array($data) && isset($data['code'], $data['message'])) {
            $wpCode = (string) $data['code'];
            $wpMessage = (string) $data['message'];
            $status = (int) ($data['data']['status'] ?? $restResponse->get_status() ?: 400);
            $details = is_array($data['data'] ?? null) && isset($data['data']['details']) && is_array($data['data']['details'])
                ? $data['data']['details']
                : [];

            $mapped = self::mapCodeAndMessageToEnvelope($wpCode, $wpMessage, $status, $details);
            if (is_array($data['data'] ?? null) && isset($data['data']['retry_after'])) {
                $mapped->header('Retry-After', (string) (int) $data['data']['retry_after']);
            }
            McpLogger::logResponse($mapped, $request);
            return $mapped;
        }

        McpLogger::logResponse($restResponse, $request);
        return $restResponse;
    }

    private static function convertWpError(\WP_Error $error): \WP_REST_Response
    {
        $code = (string) $error->get_error_code();
        $message = $error->get_error_message();
        $errorData = $error->get_error_data();
        $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 400;
        $details = is_array($errorData) && isset($errorData['details']) && is_array($errorData['details'])
            ? $errorData['details']
            : [];

        $response = self::mapCodeAndMessageToEnvelope($code, $message, $status, $details);
        if (is_array($errorData) && isset($errorData['retry_after'])) {
            $response->header('Retry-After', (string) (int) $errorData['retry_after']);
        }

        return $response;
    }

    private static function mapCodeAndMessageToEnvelope(string $code, string $message, int $status, array $details = []): \WP_REST_Response
    {
        if (in_array($code, ['rest_invalid_json', 'rest_invalid_param', 'rest_missing_callback_param'], true)) {
            return self::error('validation_failed', $message, 422, $details);
        }
        if ($code === 'rest_no_route') {
            return self::error('not_found', 'Requested endpoint does not exist.', 404, $details);
        }
        if ($status === 400) {
            $status = 422;
        }
        return self::error($code, $message, $status, $details);
    }

    private static function isOurRoute(string $route): bool
    {
        return $route === '/' . self::NAMESPACE || str_starts_with($route, '/' . self::NAMESPACE . '/');
    }
}
