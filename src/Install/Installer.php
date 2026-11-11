<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Install;

use LiveNetworks\BuilderConnector\Builders\Detector;

final class Installer
{
    public const OPTION_DB_VERSION = 'ln_builder_db_version';
    public const OPTION_SITE_ID = 'ln_builder_site_id';
    public const OPTION_EXTRA_SECRET = 'ln_builder_extra_secret';
    public const OPTION_BRIDGE_USER_ID = 'ln_builder_bridge_user_id';
    public const OPTION_CONTENT_OWNER = 'ln_builder_content_owner';
    public const OPTION_DETECTED_STATUS = 'ln_builder_detected_status';
    public const ROLE_NAME = 'ln_builder_bridge';
    public const USER_LOGIN = 'ln-builder-connector';
    public const CRON_HOOK = 'ln_builder_hourly_cleanup';
    public const CRON_JWKS_HOOK = 'ln_builder_daily_jwks_refresh';

    public const CAPABILITIES = [
        'read' => true,
        'edit_posts' => true,
        'edit_others_posts' => true,
        'edit_published_posts' => true,
        'edit_private_posts' => true,
        'edit_pages' => true,
        'edit_others_pages' => true,
        'edit_published_pages' => true,
        'edit_private_pages' => true,
        'bricks_full_access' => true,
        'ln_builder_read' => true,
        'ln_builder_write_content' => true,
        'ln_builder_publish' => true,
        'ln_builder_write_design' => true,
    ];

    public static function registerHooks(): void
    {
        register_activation_hook(LN_BUILDER_PLUGIN_FILE, [self::class, 'activate']);
        register_deactivation_hook(LN_BUILDER_PLUGIN_FILE, [self::class, 'deactivate']);

        add_action('plugins_loaded', [self::class, 'maybeUpgradeDb']);
        add_action('switch_theme', [self::class, 'refreshDetectedBuilder']);
        add_action('activated_plugin', [self::class, 'refreshDetectedBuilder']);
        add_action('deactivated_plugin', [self::class, 'refreshDetectedBuilder']);
        add_action(self::CRON_HOOK, [self::class, 'runCleanup']);
        add_action(self::CRON_JWKS_HOOK, [\LiveNetworks\BuilderConnector\Auth\JwksClient::class, 'refresh']);

        self::registerUserGuards();
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            wp_die(
                esc_html__('LN Builder Connector supports single-site installations only and cannot be network-activated.', 'ln-builder-connector'),
                esc_html__('Plugin Activation Error', 'ln-builder-connector'),
                ['back_link' => true]
            );
        }

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            wp_die(
                esc_html__('LN Builder Connector requires PHP 8.1 or newer.', 'ln-builder-connector'),
                esc_html__('Plugin Activation Error', 'ln-builder-connector'),
                ['back_link' => true]
            );
        }

        if (!extension_loaded('sodium')) {
            wp_die(
                esc_html__('LN Builder Connector requires the PHP sodium extension for Ed25519 cryptographic verification.', 'ln-builder-connector'),
                esc_html__('Plugin Activation Error', 'ln-builder-connector'),
                ['back_link' => true]
            );
        }

        global $wp_version;
        if (isset($wp_version) && version_compare((string) $wp_version, '6.4', '<')) {
            wp_die(
                esc_html__('LN Builder Connector requires WordPress 6.4 or newer.', 'ln-builder-connector'),
                esc_html__('Plugin Activation Error', 'ln-builder-connector'),
                ['back_link' => true]
            );
        }

        self::ensureDatabase();
        self::ensureOptions();
        self::ensureRoleAndUser();
        self::refreshDetectedBuilder();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_JWKS_HOOK)) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_JWKS_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        $jwksTimestamp = wp_next_scheduled(self::CRON_JWKS_HOOK);
        if ($jwksTimestamp !== false) {
            wp_unschedule_event($jwksTimestamp, self::CRON_JWKS_HOOK);
        }
    }

    public static function maybeUpgradeDb(): void
    {
        $installedVersion = (string) get_option(self::OPTION_DB_VERSION, '');
        if ($installedVersion !== LN_BUILDER_DB_VERSION) {
            self::ensureDatabase();
            self::ensureOptions();
            self::ensureRoleAndUser();
        }
    }

    public static function ensureDatabase(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $snapshotsTable = $wpdb->prefix . 'ln_builder_snapshots';
        $auditTable = $wpdb->prefix . 'ln_builder_audit';
        $jtiTable = $wpdb->prefix . 'ln_builder_jti';
        $idempotencyTable = $wpdb->prefix . 'ln_builder_idempotency';

        $sql = "CREATE TABLE {$snapshotsTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(20) NOT NULL,
  object_key varchar(100) NOT NULL,
  area varchar(20) DEFAULT NULL,
  revision varchar(20) NOT NULL,
  payload longtext NOT NULL,
  reason varchar(30) NOT NULL,
  actor varchar(191) NOT NULL DEFAULT '',
  actor_name varchar(100) DEFAULT NULL,
  org varchar(64) DEFAULT NULL,
  session varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object_lookup (object_type,object_key,created_at),
  KEY created_at (created_at)
) {$charsetCollate};
CREATE TABLE {$auditTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  actor varchar(191) NOT NULL DEFAULT '',
  actor_name varchar(100) DEFAULT NULL,
  org varchar(64) DEFAULT NULL,
  session varchar(191) NOT NULL DEFAULT '',
  kid varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  operation varchar(100) NOT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_key varchar(100) NOT NULL DEFAULT '',
  snapshot_id bigint(20) unsigned DEFAULT NULL,
  result varchar(64) NOT NULL,
  details longtext DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY object_lookup (object_type,object_key,created_at),
  KEY actor_lookup (actor,created_at),
  KEY org_lookup (org,created_at),
  KEY created_at (created_at)
) {$charsetCollate};
CREATE TABLE {$jtiTable} (
  jti varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kid varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (jti),
  KEY expires_at (expires_at)
) {$charsetCollate};
CREATE TABLE {$idempotencyTable} (
  idem_key varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kid varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status_code smallint(6) NOT NULL DEFAULT 0,
  response longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (idem_key,kid),
  KEY created_at (created_at)
) {$charsetCollate};";

        dbDelta($sql);
        update_option(self::OPTION_DB_VERSION, LN_BUILDER_DB_VERSION, true);
    }

    public static function ensureOptions(): void
    {
        $siteId = (string) get_option(self::OPTION_SITE_ID, '');
        if ($siteId === '') {
            update_option(self::OPTION_SITE_ID, wp_generate_uuid4(), true);
        }

        $extraSecret = (string) get_option(self::OPTION_EXTRA_SECRET, '');
        if ($extraSecret === '') {
            update_option(self::OPTION_EXTRA_SECRET, bin2hex(random_bytes(32)), true);
        }

        $ownerId = (int) get_option(self::OPTION_CONTENT_OWNER, 0);
        if ($ownerId <= 0 || !get_userdata($ownerId)) {
            $admins = get_users([
                'role' => 'administrator',
                'number' => 1,
                'fields' => 'ID',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            if (!empty($admins[0])) {
                update_option(self::OPTION_CONTENT_OWNER, (int) $admins[0], false);
            }
        }
    }

    public static function ensureRoleAndUser(): void
    {
        $role = get_role(self::ROLE_NAME);
        if ($role === null) {
            add_role(self::ROLE_NAME, 'ln-builder Bridge', self::CAPABILITIES);
        } else {
            foreach (self::CAPABILITIES as $cap => $grant) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap, $grant);
                }
            }
        }

        $storedUserId = (int) get_option(self::OPTION_BRIDGE_USER_ID, 0);
        $existingByLogin = get_user_by('login', self::USER_LOGIN);

        if ($existingByLogin instanceof \WP_User) {
            if ($storedUserId > 0 && (int) $existingByLogin->ID === $storedUserId) {
                $existingByLogin->set_role(self::ROLE_NAME);
                return;
            }
            // Refuse to hijack an existing user with the same login that was not created by us
            throw new \RuntimeException(
                sprintf("A user with login '%s' already exists and is not managed by ln-builder-bridge.", self::USER_LOGIN)
            );
        }

        $host = parse_url((string) home_url(), PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? $host : 'localhost.invalid';
        $email = 'ln-builder-bridge-' . substr(bin2hex(random_bytes(4)), 0, 8) . '@' . $domain;

        $userId = wp_insert_user([
            'user_login' => self::USER_LOGIN,
            'user_pass' => wp_generate_password(64, true, true),
            'user_email' => $email,
            'display_name' => 'LN Builder Connector',
            'role' => self::ROLE_NAME,
        ]);

        if (is_wp_error($userId)) {
            throw new \RuntimeException('Failed to create LN Builder Connector user: ' . $userId->get_error_message());
        }

        update_option(self::OPTION_BRIDGE_USER_ID, (int) $userId, true);
    }

    public static function refreshDetectedBuilder(): void
    {
        Detector::clearCache();
        $detected = Detector::detect();
        update_option(self::OPTION_DETECTED_STATUS, [
            'builder' => $detected['builder'],
            'version' => $detected['version'],
            'reason' => $detected['reason'],
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], false);
    }

    public static function registerUserGuards(): void
    {
        // Block interactive logins (wp-login.php, XML-RPC) for the bridge user.
        // Note: REST Application Passwords run on `determine_current_user` via `wp_validate_application_password`,
        // which calls `wp_authenticate_application_password` directly and does NOT run this `authenticate` filter.
        add_filter('authenticate', static function ($user, string $username = '') {
            $bridgeUserId = (int) get_option(self::OPTION_BRIDGE_USER_ID, 0);
            if ($bridgeUserId <= 0) {
                return $user;
            }
            if ($username === self::USER_LOGIN || ($user instanceof \WP_User && (int) $user->ID === $bridgeUserId)) {
                return new \WP_Error(
                    'ln_builder_interactive_login_disabled',
                    __('Interactive login is disabled for the ln-builder-bridge system account.', 'ln-builder-connector')
                );
            }
            return $user;
        }, 30, 2);

        // Disable password resets for the bridge user
        add_filter('allow_password_reset', static function (bool $allow, int $userId): bool {
            $bridgeUserId = (int) get_option(self::OPTION_BRIDGE_USER_ID, 0);
            if ($bridgeUserId > 0 && $userId === $bridgeUserId) {
                return false;
            }
            return $allow;
        }, 10, 2);

        // Allow Application Passwords for the bridge user ONLY when Dev Auth is enabled
        add_filter('wp_is_application_passwords_available_for_user', static function (bool $available, \WP_User $user): bool {
            $bridgeUserId = (int) get_option(self::OPTION_BRIDGE_USER_ID, 0);
            if ($bridgeUserId > 0 && (int) $user->ID === $bridgeUserId) {
                return self::isDevAuthEnabled();
            }
            return $available;
        }, 10, 2);
    }

    public static function isDevAuthEnabled(): bool
    {
        if (!defined('LN_BUILDER_DEV_AUTH') || LN_BUILDER_DEV_AUTH !== true) {
            return false;
        }
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') {
            return false;
        }
        return true;
    }

    public static function extraSecret(): string
    {
        if (defined('LN_BUILDER_EXTRA_SECRET') && is_string(LN_BUILDER_EXTRA_SECRET) && LN_BUILDER_EXTRA_SECRET !== '') {
            return LN_BUILDER_EXTRA_SECRET;
        }
        $secret = (string) get_option(self::OPTION_EXTRA_SECRET, '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            update_option(self::OPTION_EXTRA_SECRET, $secret, true);
        }
        return $secret;
    }

    public static function contentOwnerId(): int
    {
        $ownerId = (int) get_option(self::OPTION_CONTENT_OWNER, 0);
        if ($ownerId > 0 && get_userdata($ownerId) && user_can($ownerId, 'edit_pages')) {
            return $ownerId;
        }

        $admins = get_users([
            'role' => 'administrator',
            'number' => 1,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!empty($admins[0])) {
            $fallbackId = (int) $admins[0];
            update_option(self::OPTION_CONTENT_OWNER, $fallbackId, false);
            return $fallbackId;
        }

        return (int) get_option(self::OPTION_BRIDGE_USER_ID, 1);
    }

    public static function runCleanup(): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $jtiTable = $wpdb->prefix . 'ln_builder_jti';
        $idempotencyTable = $wpdb->prefix . 'ln_builder_idempotency';
        $snapshotsTable = $wpdb->prefix . 'ln_builder_snapshots';
        $auditTable = $wpdb->prefix . 'ln_builder_audit';

        // 1. Expired JTI rows
        $wpdb->query($wpdb->prepare("DELETE FROM {$jtiTable} WHERE expires_at < %s", $now));

        // 2. Idempotency rows older than 24h
        $cutoff24h = gmdate('Y-m-d H:i:s', time() - 86400);
        $wpdb->query($wpdb->prepare("DELETE FROM {$idempotencyTable} WHERE created_at < %s", $cutoff24h));

        // 3. Snapshots older than retention days AND beyond last N per object
        $retentionDays = max(1, (int) get_option('ln_builder_snapshot_days', 30));
        $keepCount = max(1, (int) get_option('ln_builder_snapshot_keep_count', 20));
        $cutoffSnapshots = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $objectsWithSnapshots = $wpdb->get_results(
            "SELECT object_type, object_key, COUNT(*) AS cnt FROM {$snapshotsTable} GROUP BY object_type, object_key HAVING cnt > " . (int) $keepCount,
            ARRAY_A
        );
        if (is_array($objectsWithSnapshots)) {
            foreach ($objectsWithSnapshots as $obj) {
                $oType = (string) ($obj['object_type'] ?? '');
                $oKey = (string) ($obj['object_key'] ?? '');
                $boundaryId = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$snapshotsTable} WHERE object_type = %s AND object_key = %s ORDER BY id DESC LIMIT 1 OFFSET %d",
                    $oType,
                    $oKey,
                    $keepCount - 1
                ));
                if ($boundaryId !== null) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$snapshotsTable} WHERE object_type = %s AND object_key = %s AND id < %d AND created_at < %s",
                        $oType,
                        $oKey,
                        (int) $boundaryId,
                        $cutoffSnapshots
                    ));
                }
            }
        }

        // 4. Audit rows older than retention days (default 365)
        $auditDays = max(1, (int) get_option('ln_builder_audit_days', 365));
        $cutoffAudit = gmdate('Y-m-d H:i:s', time() - ($auditDays * 86400));
        $wpdb->query($wpdb->prepare("DELETE FROM {$auditTable} WHERE created_at < %s", $cutoffAudit));

        // Privacy: Clear actor_name in snapshot rows older than audit retention
        $wpdb->query($wpdb->prepare(
            "UPDATE {$snapshotsTable} SET actor_name = NULL WHERE created_at < %s AND actor_name IS NOT NULL",
            $cutoffAudit
        ));

        // 5. Rate-limit minute buckets older than current minute
        $currentMinute = (int) floor(time() / 60);
        \LiveNetworks\BuilderConnector\Auth\SecurityGuard::resetCounters($currentMinute);
    }

    public static function uninstall(): void
    {
        global $wpdb;

        // Best effort: notify panel that site disconnected before clearing data
        if (class_exists(\LiveNetworks\BuilderConnector\Connect\ConnectClient::class)) {
            \LiveNetworks\BuilderConnector\Connect\ConnectClient::disconnect();
        }

        self::deactivate();

        $tables = [
            $wpdb->prefix . 'ln_builder_snapshots',
            $wpdb->prefix . 'ln_builder_audit',
            $wpdb->prefix . 'ln_builder_jti',
            $wpdb->prefix . 'ln_builder_idempotency',
        ];
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        $bridgeUserId = (int) get_option(self::OPTION_BRIDGE_USER_ID, 0);
        if ($bridgeUserId > 0) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            // Reassign any authored revisions or objects to the site content owner
            $reassignTarget = self::contentOwnerId();
            wp_delete_user($bridgeUserId, $reassignTarget > 0 && $reassignTarget !== $bridgeUserId ? $reassignTarget : null);
        }

        remove_role(self::ROLE_NAME);

        // Delete all _ln_builder_* postmeta entries (components and attribution markers)
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_ln\_builder\_%'");

        // Delete all last_used timestamp options and rate-limit transient options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ln\_builder\_key\_last\_used\_%' OR option_name LIKE '\_ln\_builder\_rl\_%'");

        $options = [
            self::OPTION_DB_VERSION,
            self::OPTION_SITE_ID,
            self::OPTION_EXTRA_SECRET,
            self::OPTION_BRIDGE_USER_ID,
            self::OPTION_CONTENT_OWNER,
            self::OPTION_DETECTED_STATUS,
            \LiveNetworks\BuilderConnector\Connect\PlatformState::OPTION_PLATFORM,
            \LiveNetworks\BuilderConnector\Auth\JwksClient::OPTION_JWKS_CACHE,
            \LiveNetworks\BuilderConnector\Auth\JwksClient::OPTION_LAST_REFRESH_ATTEMPT,
            \LiveNetworks\BuilderConnector\Auth\JwksClient::OPTION_LAST_SUCCESSFUL_FETCH,
            'ln_builder_keys',
            'ln_builder_meta',
            'ln_builder_token_discipline',
            'ln_builder_include_theme_tokens',
            'ln_builder_components_limit',
            'ln_builder_smoke_test_page_id',
            'ln_builder_snapshot_days',
            'ln_builder_snapshot_keep_count',
            'ln_builder_audit_days',
            'ln_builder_rate_limit_read',
            'ln_builder_rate_limit_write',
            \LiveNetworks\BuilderConnector\Auth\SecurityGuard::OPTION_RATE_LIMIT_ACTOR_WRITE,
            'ln_builder_verify_render',
            'ln_builder_ip_allowlist',
            'ln_builder_trusted_proxies',
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
        delete_transient(\LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder::TRANSIENT_KEY);
    }
}
