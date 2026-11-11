<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Rest;

use LiveNetworks\BuilderConnector\Audit\AuditLogger;
use LiveNetworks\BuilderConnector\Auth\JwtVerifier;
use LiveNetworks\BuilderConnector\Auth\RequestContext;
use LiveNetworks\BuilderConnector\Builders\BuilderAdapter;
use LiveNetworks\BuilderConnector\Builders\Detector;
use LiveNetworks\BuilderConnector\Connect\ConnectClient;
use LiveNetworks\BuilderConnector\Connect\PlatformState;
use LiveNetworks\BuilderConnector\Converter\Compactor;
use LiveNetworks\BuilderConnector\Converter\DesignValidator;
use LiveNetworks\BuilderConnector\Converter\Expander;
use LiveNetworks\BuilderConnector\Converter\Patcher;
use LiveNetworks\BuilderConnector\Converter\Problem;
use LiveNetworks\BuilderConnector\Converter\Revision;
use LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder;
use LiveNetworks\BuilderConnector\Install\Installer;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;
use LiveNetworks\BuilderConnector\Snapshots\SnapshotRepository;
use LiveNetworks\BuilderConnector\Write\Pipeline;

final class Router
{
    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(Responder::NAMESPACE, '/connect/confirm', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'handleConnectConfirm'],
        ]);

        register_rest_route(Responder::NAMESPACE, '/status', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleStatus'], false),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-context', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleDesignContext'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleGetDesignSystem'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/colors', [
            'methods' => 'PATCH',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handlePatchDesignColors'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/variables', [
            'methods' => 'PATCH',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handlePatchDesignVariables'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/classes', [
            'methods' => 'PATCH',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handlePatchDesignClasses'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/theme-styles/(?P<name>[a-zA-Z0-9_-]+)', [
            'methods' => 'PATCH',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handlePatchDesignThemeStyle'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/preset', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handleApplyDesignPreset'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/design-system/managed-css/resolve', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('design:write'),
            'callback' => Responder::wrap([self::class, 'handleResolveManagedCss'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/elements', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleElements'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/templates', [
            [
                'methods' => 'GET',
                'permission_callback' => JwtVerifier::permissionCallback('read'),
                'callback' => Responder::wrap([self::class, 'handleListTemplates'], true),
            ],
            [
                'methods' => 'POST',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handleCreateTemplate'], true),
            ],
        ]);

        register_rest_route(Responder::NAMESPACE, '/templates/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'permission_callback' => JwtVerifier::permissionCallback('read'),
                'callback' => Responder::wrap([self::class, 'handleGetTemplate'], true),
            ],
            [
                'methods' => 'PATCH',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handlePatchTemplate'], true),
            ],
            [
                'methods' => 'PUT',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handlePutTemplate'], true),
            ],
        ]);

        register_rest_route(Responder::NAMESPACE, '/templates/(?P<id>\d+)/publish', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('publish'),
            'callback' => Responder::wrap([self::class, 'handlePublishTemplate'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/pages', [
            [
                'methods' => 'GET',
                'permission_callback' => JwtVerifier::permissionCallback('read'),
                'callback' => Responder::wrap([self::class, 'handleListPages'], true),
            ],
            [
                'methods' => 'POST',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handleCreatePage'], true),
            ],
        ]);

        register_rest_route(Responder::NAMESPACE, '/pages/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'permission_callback' => JwtVerifier::permissionCallback('read'),
                'callback' => Responder::wrap([self::class, 'handleGetPage'], true),
            ],
            [
                'methods' => 'PATCH',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handlePatchPage'], true),
            ],
            [
                'methods' => 'PUT',
                'permission_callback' => JwtVerifier::permissionCallback('content:write'),
                'callback' => Responder::wrap([self::class, 'handlePutPage'], true),
            ],
        ]);

        register_rest_route(Responder::NAMESPACE, '/pages/(?P<id>\d+)/publish', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('publish'),
            'callback' => Responder::wrap([self::class, 'handlePublishPage'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/validate', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleValidate'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/snapshots', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleListSnapshots'], false),
        ]);

        register_rest_route(Responder::NAMESPACE, '/snapshots/(?P<id>snp_\d+|\d+)/restore', [
            'methods' => 'POST',
            'permission_callback' => JwtVerifier::permissionCallback('content:write|design:write'),
            'callback' => Responder::wrap([self::class, 'handleRestoreSnapshot'], true),
        ]);

        register_rest_route(Responder::NAMESPACE, '/audit', [
            'methods' => 'GET',
            'permission_callback' => JwtVerifier::permissionCallback('read'),
            'callback' => Responder::wrap([self::class, 'handleListAudit'], false),
        ]);
    }

    public static function handleStatus(\WP_REST_Request $request, ?BuilderAdapter $adapter): \WP_REST_Response
    {
        global $wp_version;

        $detected = Detector::detect();
        $ctx = RequestContext::current();
        $envType = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        $platformStatus = 'not_connected';
        if (class_exists(\LiveNetworks\BuilderConnector\Connect\PlatformState::class)) {
            $rawStatus = \LiveNetworks\BuilderConnector\Connect\PlatformState::status();
            if ($rawStatus === \LiveNetworks\BuilderConnector\Connect\PlatformState::STATUS_CONNECTED) {
                $platformStatus = 'active';
            } elseif ($rawStatus === \LiveNetworks\BuilderConnector\Connect\PlatformState::STATUS_PENDING_APPROVAL) {
                $platformStatus = 'pending_approval';
            } else {
                $platformStatus = $rawStatus;
            }
        }

        $data = [
            'site_name' => get_bloginfo('name'),
            'platform_status' => $platformStatus,
            'plugin_version' => LN_BUILDER_VERSION,
            'contract_version' => LN_BUILDER_CONTRACT_VERSION,
            'wp_version' => isset($wp_version) ? (string) $wp_version : get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'builder' => $detected['builder'],
            'builder_version' => $detected['version'],
            'capabilities' => $adapter !== null ? $adapter->capabilities()->toArray() : null,
            'scopes' => $ctx !== null ? $ctx->scopes() : [],
            'dev_auth' => Installer::isDevAuthEnabled(),
            'environment_type' => $envType,
        ];

        if ($detected['reason'] !== null) {
            $data['reason'] = $detected['reason'];
        }

        if (defined('LN_BUILDER_DEV_AUTH') && LN_BUILDER_DEV_AUTH === true && $envType === 'production') {
            $data['dev_auth_notice'] = 'LN_BUILDER_DEV_AUTH is set to true, but ignored because wp_get_environment_type() is production.';
        }

        return Responder::success($data);
    }

    public static function handleDesignContext(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $metaRepo = new MetaRepository();
        $builder = new DesignContextBuilder();
        $data = $builder->build($adapter, $metaRepo, true);

        return Responder::success($data);
    }

    public static function handleGetDesignSystem(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $metaRepo = new MetaRepository();
        $data = $adapter->designSystem($metaRepo);

        return Responder::success($data);
    }

    public static function handlePatchDesignColors(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchDesignColors($body);
    }

    public static function handlePatchDesignVariables(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchDesignVariables($body);
    }

    public static function handlePatchDesignClasses(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchDesignClasses($body);
    }

    public static function handlePatchDesignThemeStyle(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $name = (string) $request->get_param('name');
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchDesignThemeStyle($name, $body);
    }

    public static function handleApplyDesignPreset(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        return self::withIdempotency($request, static function () use ($adapter, $body): \WP_REST_Response {
            $pipeline = new Pipeline($adapter);
            return $pipeline->applyDesignPreset($body);
        });
    }

    public static function handleResolveManagedCss(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['mode']) || !in_array($body['mode'], ['reimport', 'overwrite'], true)) {
            return Responder::error(
                'validation_failed',
                "Field 'mode' is required and must be 'reimport' or 'overwrite'.",
                422,
                [['path' => 'mode', 'code' => 'invalid_value', 'message' => "Expected 'reimport' or 'overwrite'."]]
            );
        }

        $mode = (string) $body['mode'];
        if ($adapter instanceof \LiveNetworks\BuilderConnector\Builders\Elementor\ElementorAdapter && $mode === 'overwrite') {
            $adapter->syncManagedCssToKit();
        }

        return Responder::success([
            'resolved' => true,
            'mode' => $mode,
            'builder' => $adapter->name(),
        ]);
    }

    public static function handleElements(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        return Responder::success([
            'builder' => $adapter->name(),
            'elements' => $adapter->elementRegistry(),
        ]);
    }

    public static function handleListTemplates(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $filter = [
            'type' => $request->get_param('type'),
            'search' => $request->get_param('search'),
            'status' => $request->get_param('status'),
            'component' => $request->get_param('component'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ];

        $result = $adapter->templatesWithCount($filter);
        return Responder::success(
            ['items' => $result['items']],
            200,
            [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );
    }

    public static function handleGetTemplate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return Responder::error('not_found', sprintf('Template %d not found.', $id), 404);
        }

        $details = $adapter->templateDetails($id);
        if ($details === null) {
            return Responder::error('not_found', sprintf('Template %d not found.', $id), 404);
        }

        $templateType = (string) ($details['type'] ?? 'content');
        $area = $adapter->areaForTemplateType($templateType);
        $native = $adapter->contentArea($id, $area);

        $metaRepo = new MetaRepository();
        $vocab = $adapter->buildSiteVocabulary($metaRepo);
        $compactor = new Compactor();

        $compactResult = $compactor->compact($native, $area, $adapter->getSchema(), $vocab);
        $docData = $compactResult->data();
        $details['revision'] = (string) ($docData['revision'] ?? Revision::compute($native));
        $details['document'] = $docData;

        $extraMeta = [];
        $warnings = $compactResult->warnings();
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return Responder::success($details, 200, $extraMeta);
    }

    public static function handleCreateTemplate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        return self::withIdempotency($request, static function () use ($adapter, $body): \WP_REST_Response {
            $pipeline = new Pipeline($adapter);
            return $pipeline->createTemplate($body);
        });
    }

    public static function handlePatchTemplate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchObject('template', $id, $body);
    }

    public static function handlePutTemplate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->putObject('template', $id, $body);
    }

    public static function handlePublishTemplate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $pipeline = new Pipeline($adapter);
        return $pipeline->publishObject('template', $id);
    }

    public static function handleListPages(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $filter = [
            'status' => $request->get_param('status'),
            'search' => $request->get_param('search'),
            'parent' => $request->get_param('parent'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ];

        $result = $adapter->pagesWithCount($filter);
        return Responder::success(
            ['items' => $result['items']],
            200,
            [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );
    }

    public static function handleGetPage(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return Responder::error('not_found', sprintf('Page %d not found.', $id), 404);
        }

        $details = $adapter->pageDetails($id);
        if ($details === null) {
            return Responder::error('not_found', sprintf('Page %d not found.', $id), 404);
        }

        $native = $adapter->contentArea($id, 'content');

        $metaRepo = new MetaRepository();
        $vocab = $adapter->buildSiteVocabulary($metaRepo);
        $compactor = new Compactor();

        $compactResult = $compactor->compact($native, 'content', $adapter->getSchema(), $vocab);
        $docData = $compactResult->data();
        $details['revision'] = (string) ($docData['revision'] ?? Revision::compute($native));
        $details['document'] = $docData;

        $extraMeta = [];
        $warnings = $compactResult->warnings();
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return Responder::success($details, 200, $extraMeta);
    }

    public static function handleCreatePage(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        return self::withIdempotency($request, static function () use ($adapter, $body): \WP_REST_Response {
            $pipeline = new Pipeline($adapter);
            return $pipeline->createPage($body);
        });
    }

    public static function handlePatchPage(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->patchObject('page', $id, $body);
    }

    public static function handlePutPage(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $pipeline = new Pipeline($adapter);
        return $pipeline->putObject('page', $id, $body);
    }

    public static function handlePublishPage(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $pipeline = new Pipeline($adapter);
        return $pipeline->publishObject('page', $id);
    }

    public static function handleListSnapshots(\WP_REST_Request $request, ?BuilderAdapter $adapter): \WP_REST_Response
    {
        $objectParam = $request->get_param('object');
        if (!is_string($objectParam) || trim($objectParam) === '' || !str_contains($objectParam, ':')) {
            return Responder::error(
                'validation_failed',
                "Query parameter 'object' is required (e.g. 'template:12' or 'page:45').",
                422,
                [['path' => 'object', 'code' => 'invalid_value', 'message' => "Query parameter 'object' must be in the format '<type>:<key>'."]]
            );
        }

        [$objectType, $objectKey] = explode(':', trim($objectParam), 2);
        if (!in_array($objectType, ['template', 'page', 'design'], true) || trim($objectKey) === '') {
            return Responder::error(
                'validation_failed',
                "Query parameter 'object' must start with 'template:', 'page:', or 'design:'.",
                422,
                [['path' => 'object', 'code' => 'invalid_value', 'message' => "Invalid object type in 'object' filter."]]
            );
        }

        $repo = new SnapshotRepository();
        $items = $repo->listByObject($objectType, trim($objectKey));

        return Responder::success([
            'object' => $objectType . ':' . trim($objectKey),
            'items' => $items,
        ]);
    }

    public static function handleRestoreSnapshot(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $idParam = (string) $request->get_param('id');
        $pipeline = new Pipeline($adapter);
        return $pipeline->restoreSnapshot($idParam);
    }

    public static function handleListAudit(\WP_REST_Request $request, ?BuilderAdapter $adapter): \WP_REST_Response
    {
        $filter = [
            'object' => $request->get_param('object'),
            'actor' => $request->get_param('actor'),
            'org' => $request->get_param('org'),
            'session' => $request->get_param('session'),
            'from' => $request->get_param('from'),
            'to' => $request->get_param('to'),
            'operation' => $request->get_param('operation'),
            'result' => $request->get_param('result'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ];

        $audit = new AuditLogger();
        $res = $audit->query($filter);

        return Responder::success(
            ['items' => $res['items']],
            200,
            [
                'total' => $res['total'],
                'page' => $res['page'],
                'per_page' => $res['per_page'],
            ]
        );
    }

    public static function handleValidate(\WP_REST_Request $request, BuilderAdapter $adapter): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return Responder::error(
                'validation_failed',
                'Request body must be a JSON object.',
                422,
                [['path' => '', 'code' => 'invalid_type', 'message' => 'Request body must be a JSON object.']]
            );
        }

        $target = $body['target'] ?? null;
        if (!is_string($target) || !in_array($target, ['document', 'patch', 'design'], true)) {
            return Responder::error(
                'validation_failed',
                "Field 'target' must be one of: document, patch, design.",
                422,
                [['path' => 'target', 'code' => 'invalid_value', 'message' => "Field 'target' must be one of: document, patch, design."]]
            );
        }

        $payload = $body['payload'] ?? null;
        if (!is_array($payload)) {
            return Responder::error(
                'validation_failed',
                "Field 'payload' must be a JSON object.",
                422,
                [['path' => 'payload', 'code' => 'invalid_type', 'message' => "Field 'payload' must be a JSON object."]]
            );
        }

        $metaRepo = new MetaRepository();
        $vocab = $adapter->buildSiteVocabulary($metaRepo);

        if ($target === 'design') {
            return self::validateDesignPayload($payload, $adapter, $metaRepo, $vocab);
        }

        if ($target === 'patch') {
            return self::validatePatchPayload($payload, $adapter, $vocab);
        }

        $document = $payload;
        $pathPrefix = 'payload';
        if (isset($payload['document']) && is_array($payload['document'])) {
            $document = $payload['document'];
            $pathPrefix = 'payload.document';
        }

        $expander = new Expander();
        $expRes = $expander->expand($document, $adapter->getSchema(), $vocab, false);

        $prefixProblem = static fn(Problem $p): Problem => new Problem(
            $p->path() !== '' ? $pathPrefix . '.' . $p->path() : $pathPrefix,
            $p->code(),
            $p->message(),
            $p->hint(),
            $p->severity()
        );

        if ($expRes->hasErrors()) {
            $prefixedErrors = array_map($prefixProblem, $expRes->errors());
            $prefixedWarnings = array_map($prefixProblem, $expRes->warnings());
            return Responder::validationFailed($prefixedErrors, $prefixedWarnings);
        }

        $extraMeta = [];
        $warnings = array_map($prefixProblem, $expRes->warnings());
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return Responder::success(['valid' => true], 200, $extraMeta);
    }

    /**
     * Validate a `target: "patch"` payload (`ops` + optional `object` and `base_revision`).
     *
     * @param array<string, mixed> $payload
     */
    private static function validatePatchPayload(
        array $payload,
        BuilderAdapter $adapter,
        \LiveNetworks\BuilderConnector\Converter\SiteVocabulary $vocab
    ): \WP_REST_Response {
        $ops = $payload['ops'] ?? (array_is_list($payload) ? $payload : null);
        $pathPrefix = isset($payload['ops']) ? 'payload' : 'payload';

        if (!is_array($ops) || $ops === [] || !array_is_list($ops)) {
            return Responder::validationFailed([
                new Problem('payload.ops', 'invalid_value', 'Field "ops" must be a non-empty array of patch operations.'),
            ]);
        }

        $storedElements = [];
        $area = 'content';

        if (isset($payload['object']) && is_string($payload['object']) && trim($payload['object']) !== '') {
            $objStr = trim($payload['object']);
            if (!preg_match('/^(template|page):(\d+)$/', $objStr, $m)) {
                return Responder::validationFailed([
                    new Problem('payload.object', 'invalid_value', "Field 'object' must be 'template:<id>' or 'page:<id>'."),
                ]);
            }
            $objType = $m[1];
            $objId = (int) $m[2];
            $details = $objType === 'template' ? $adapter->templateDetails($objId) : $adapter->pageDetails($objId);
            if ($details === null) {
                return Responder::error('not_found', sprintf('%s %d not found.', ucfirst($objType), $objId), 404);
            }

            $area = $objType === 'template' ? $adapter->areaForTemplateType((string) ($details['type'] ?? 'content')) : 'content';
            $storedElements = $adapter->contentArea($objId, $area);

            if (isset($payload['base_revision']) && is_string($payload['base_revision']) && $payload['base_revision'] !== '') {
                $currentRev = Revision::compute($storedElements);
                if ($payload['base_revision'] !== $currentRev) {
                    return Responder::error(
                        'revision_conflict',
                        sprintf('Base revision "%s" does not match current revision "%s".', $payload['base_revision'], $currentRev),
                        409,
                        [['path' => 'payload.base_revision', 'code' => 'revision_conflict', 'current_revision' => $currentRev]]
                    );
                }
            }
        } else {
            // Standalone patch validation without a target object: synthesize stub root sections for any s_... refs in ops
            $stubIds = [];
            foreach ($ops as $op) {
                if (!is_array($op)) {
                    continue;
                }
                foreach (['ref', 'after', 'before'] as $refKey) {
                    $refVal = $op[$refKey] ?? null;
                    if (is_string($refVal) && str_starts_with($refVal, 's_') && strlen($refVal) > 2) {
                        $rawId = substr($refVal, 2);
                        $stubIds[$rawId] = true;
                    }
                }
            }
            foreach (array_keys($stubIds) as $rawId) {
                $storedElements[] = [
                    'id' => (string) $rawId,
                    'name' => 'section',
                    'parent' => 0,
                    'children' => [],
                    'settings' => [],
                ];
            }
        }

        $patcher = new Patcher();
        $patchRes = $patcher->apply($storedElements, $ops, $adapter->getSchema(), $vocab, $area);

        $prefixProblem = static fn(Problem $p): Problem => new Problem(
            $p->path() !== '' ? $pathPrefix . '.' . $p->path() : $pathPrefix,
            $p->code(),
            $p->message(),
            $p->hint(),
            $p->severity()
        );

        if ($patchRes->hasErrors()) {
            return Responder::validationFailed(
                array_map($prefixProblem, $patchRes->errors()),
                array_map($prefixProblem, $patchRes->warnings())
            );
        }

        $extraMeta = [];
        $warnings = array_map($prefixProblem, $patchRes->warnings());
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return Responder::success(['valid' => true], 200, $extraMeta);
    }

    /**
     * Validate a `target: "design"` payload (`colors`, `variables`, `classes`, `theme_styles`, or `preset`).
     *
     * @param array<string, mixed> $payload
     */
    private static function validateDesignPayload(
        array $payload,
        BuilderAdapter $adapter,
        MetaRepository $metaRepo,
        \LiveNetworks\BuilderConnector\Converter\SiteVocabulary $vocab
    ): \WP_REST_Response {
        $state = $adapter->designValidationState($metaRepo);

        if (isset($payload['preset']) && is_string($payload['preset'])) {
            $presetName = trim($payload['preset']);
            if ($presetName === \LiveNetworks\BuilderConnector\DesignContext\StarterPreset::NAME) {
                $payload = array_merge(\LiveNetworks\BuilderConnector\DesignContext\StarterPreset::definition(), $payload);
            } elseif (!isset($payload['definition']) || !is_array($payload['definition'])) {
                return Responder::validationFailed([
                    new Problem('payload.preset', 'invalid_value', sprintf("Unknown preset '%s'.", $presetName)),
                ]);
            } else {
                $payload = $payload['definition'];
            }
        }

        $validator = new DesignValidator();
        $valRes = $validator->validateFullDesignPayload(
            $payload,
            $state,
            $vocab->tokenDiscipline(),
            'payload'
        );

        if ($valRes->hasErrors()) {
            return Responder::validationFailed(
                $valRes->errors(),
                $valRes->warnings()
            );
        }

        $extraMeta = [];
        $warnings = $valRes->warnings();
        if ($warnings !== []) {
            $extraMeta['warnings'] = array_map(
                static fn(Problem $w): array => $w->toArray(),
                $warnings
            );
        }

        return Responder::success(['valid' => true], 200, $extraMeta);
    }

    /**
     * Wrap a mutating POST endpoint (`POST /templates` or `POST /pages`) with Idempotency-Key handling.
     *
     * @param callable(): \WP_REST_Response $action
     */
    private static function withIdempotency(\WP_REST_Request $request, callable $action): \WP_REST_Response
    {
        $rawBody = (string) $request->get_body();
        $ctx = RequestContext::current();
        $kid = $ctx !== null ? $ctx->kid() : 'anon';
        $idemHeader = $request->get_header('idempotency_key');
        $idemKey = is_string($idemHeader) ? trim($idemHeader) : '';
        $idemStore = new IdempotencyStore();

        if ($idemKey !== '') {
            if (strlen($idemKey) > 128 || preg_match('/^[\x21-\x7E]+$/', $idemKey) !== 1) {
                return Responder::error(
                    'validation_failed',
                    'Idempotency-Key must be 1..128 printable ASCII characters.',
                    422
                );
            }

            $route = $request->get_route();
            $prefix = '/' . Responder::NAMESPACE;
            $relPath = str_starts_with($route, $prefix) ? substr($route, strlen($prefix)) : $route;
            if ($relPath === '') {
                $relPath = '/';
            }

            $normalizedHtu = JwtVerifier::normalizeHtu($relPath, $request->get_query_params());
            $requestHash = IdempotencyStore::computeRequestHash($request->get_method(), $normalizedHtu, $rawBody);
            $idemState = $idemStore->begin($idemKey, $kid, $requestHash);

            if ($idemState['state'] === 'mismatch') {
                return Responder::error(
                    'idempotency_mismatch',
                    'Idempotency-Key was already used with a different request payload.',
                    422
                );
            }

            if ($idemState['state'] === 'in_flight') {
                return Responder::error(
                    'object_locked',
                    'A request with this Idempotency-Key is currently in progress.',
                    409
                );
            }

            if ($idemState['state'] === 'replay') {
                $replayResponse = new \WP_REST_Response($idemState['body'], (int) $idemState['status']);
                $replayResponse->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $replayResponse->header('X-Idempotent-Replay', 'true');
                return $replayResponse;
            }
        }

        try {
            $response = $action();
            if ($idemKey !== '') {
                $data = $response->get_data();
                if (is_array($data)) {
                    $idemStore->complete($idemKey, $kid, $response->get_status(), $data);
                } else {
                    $idemStore->release($idemKey, $kid);
                }
            }
            return $response;
        } catch (\Throwable $e) {
            if ($idemKey !== '') {
                $idemStore->release($idemKey, $kid);
            }
            throw $e;
        }
    }

    /**
     * Handle incoming server-to-server webhook confirmation from the panel.
     */
    public static function handleConnectConfirm(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->get_params();
        }

        $result = ConnectClient::handleDirectConfirmation($payload);
        if (!$result['success']) {
            return Responder::error(
                $result['code'] ?? 'connect_failed',
                $result['error'] ?? 'Connection confirmation failed.',
                400
            );
        }

        return Responder::success([
            'status' => $result['status'] ?? PlatformState::STATUS_CONNECTED,
            'connected' => true,
        ]);
    }
}
