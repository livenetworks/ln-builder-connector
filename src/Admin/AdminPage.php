<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Admin;

use LiveNetworks\BuilderConnector\Auth\JwksClient;
use LiveNetworks\BuilderConnector\Builders\Detector;
use LiveNetworks\BuilderConnector\Connect\ConnectClient;
use LiveNetworks\BuilderConnector\Connect\PlatformState;
use LiveNetworks\BuilderConnector\Install\Installer;

final class AdminPage
{
    public const MENU_SLUG = 'ln-builder';

    public static function registerHooks(): void
    {
        // Must hook init to handle Connect returns early (including CORS preflights and AJAX returns)
        // before wp-admin auth_redirect() runs.
        add_action('init', [self::class, 'handleConnectReturnEarly']);

        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'handleActions']);
        add_action('admin_notices', [self::class, 'renderDevAuthNotice']);
        add_action('add_meta_boxes', [self::class, 'registerMetaBoxes']);
        add_action('wp_ajax_ln_builder_check_status', [self::class, 'ajaxCheckStatus']);
    }

    public static function addMenu(): void
    {
        add_menu_page(
            'LN Builder Connector',
            'LN Builder',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'renderPage'],
            'dashicons-rest-api',
            80
        );
    }

    /**
     * Handle return from MCP panel early on 'init' before auth_redirect().
     * Handles CORS OPTIONS preflights from panel AJAX as well as standard GET returns.
     */
    public static function handleConnectReturnEarly(): void
    {
        if (
            !isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG
            || !isset($_GET['connect']) || $_GET['connect'] !== 'return'
        ) {
            return;
        }

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $isOptions = ($method === 'OPTIONS');

        // Restrict CORS to trusted panel origin only (prevents credential reflection & CSRF)
        $panelOrigin = rtrim(PlatformState::panelUrl(), '/');
        $allowedOrigins = array_values(array_unique(array_filter([
            $panelOrigin,
            'https://wpconsole.live.net.mk',
            'https://wpbridge.live.net.mk',
        ])));
        $allowedOrigins = apply_filters('ln_builder_cors_allowed_origins', $allowedOrigins);

        $originMatch = false;
        if ($origin !== '') {
            $normalizedOrigin = rtrim($origin, '/');
            foreach ($allowedOrigins as $allowed) {
                if (hash_equals(rtrim($allowed, '/'), $normalizedOrigin)) {
                    $originMatch = true;
                    break;
                }
            }
        }

        if ($originMatch) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
            header('Vary: Origin');
        } elseif ($isOptions) {
            status_header(403);
            exit;
        }

        if ($isOptions) {
            status_header(204);
            exit;
        }

        $code = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');
        $userId = get_current_user_id();

        if ($userId === 0 && $state !== '') {
            $stateHash = hash('sha256', $state);
            $storedUserId = get_transient(ConnectClient::STATE_USER_PREFIX . $stateHash);
            if (is_numeric($storedUserId) && (int) $storedUserId > 0) {
                $userId = (int) $storedUserId;
            }
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));

        // If the webhook already successfully connected the site, redirect directly
        if (PlatformState::isConnected()) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo (string) json_encode([
                    'success' => true,
                    'status' => PlatformState::status(),
                    'redirect' => admin_url('admin.php?page=' . self::MENU_SLUG . '&connected=1'),
                ]);
            } else {
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&connected=1'));
            }
            exit;
        }

        $result = ConnectClient::handleReturn($code, $state, $userId);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            if ($result['success']) {
                echo (string) json_encode([
                    'success' => true,
                    'status' => $result['status'] ?? PlatformState::STATUS_CONNECTED,
                    'redirect' => admin_url('admin.php?page=' . self::MENU_SLUG . '&connected=1'),
                ]);
            } else {
                status_header(400);
                echo (string) json_encode([
                    'success' => false,
                    'error' => $result['error'] ?? 'Connection exchange failed.',
                ]);
            }
            exit;
        }

        if ($result['success']) {
            $statusParam = ($result['status'] === PlatformState::STATUS_PENDING_APPROVAL)
                ? '&pending=1'
                : '&connected=1';
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . $statusParam));
        } else {
            $errorParam = '&error=' . urlencode((string) ($result['error'] ?? 'Connection exchange failed.'));
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . $errorParam));
        }
        exit;
    }

    /**
     * AJAX status check for polling while in STATUS_CONNECTING.
     */
    public static function ajaxCheckStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }

        wp_send_json_success([
            'status' => PlatformState::status(),
            'site_key' => PlatformState::siteKey(),
        ]);
    }

    public static function handleActions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 1. Handle Connect return from panel
        if (
            isset($_GET['page']) && $_GET['page'] === self::MENU_SLUG
            && isset($_GET['connect']) && $_GET['connect'] === 'return'
        ) {
            if (PlatformState::isConnected()) {
                $statusParam = (PlatformState::status() === PlatformState::STATUS_PENDING_APPROVAL)
                    ? '&pending=1'
                    : '&connected=1';
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . $statusParam));
                exit;
            }

            $code = (string) ($_GET['code'] ?? '');
            $state = (string) ($_GET['state'] ?? '');
            $userId = get_current_user_id();

            $result = ConnectClient::handleReturn($code, $state, $userId);
            if ($result['success']) {
                $statusParam = ($result['status'] === PlatformState::STATUS_PENDING_APPROVAL)
                    ? '&pending=1'
                    : '&connected=1';
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . $statusParam));
            } else {
                $errorParam = '&error=' . urlencode((string) ($result['error'] ?? 'Connection exchange failed.'));
                wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . $errorParam));
            }
            exit;
        }

        // 2. Handle POST start connect
        if (isset($_POST['ln_builder_action']) && $_POST['ln_builder_action'] === 'connect') {
            check_admin_referer('ln_builder_connect_action');
            $url = ConnectClient::buildConnectUrl(get_current_user_id());
            wp_redirect($url);
            exit;
        }

        // 3. Handle POST disconnect
        if (isset($_POST['ln_builder_action']) && $_POST['ln_builder_action'] === 'disconnect') {
            check_admin_referer('ln_builder_disconnect_action');
            ConnectClient::disconnect(get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&disconnected=1'));
            exit;
        }

        // 4. Handle POST reset registration
        if (isset($_POST['ln_builder_action']) && $_POST['ln_builder_action'] === 'reset_registration') {
            check_admin_referer('ln_builder_reset_action');
            $regenerateUuid = !empty($_POST['regenerate_site_id']);
            ConnectClient::resetRegistration(get_current_user_id(), $regenerateUuid);
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&reset=1'));
            exit;
        }
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'ln-builder-connector'));
        }

        $detected = Detector::detect();
        $state = PlatformState::get();
        $status = $state['status'];
        $siteId = (string) get_option(Installer::OPTION_SITE_ID, '');
        $connectedUrl = $state['connected_url'] ?? null;
        $currentUrl = home_url();

        $urlChanged = $connectedUrl !== null && $connectedUrl !== '' && parse_url($connectedUrl, PHP_URL_HOST) !== parse_url($currentUrl, PHP_URL_HOST);
        $jwksStale = PlatformState::isConnected() && JwksClient::isStale();

        ?>
        <div class="wrap" style="max-width: 680px; margin-top: 30px;">
            <h1><?php echo esc_html__('LN Builder Connector', 'ln-builder-connector'); ?></h1>

            <?php if (isset($_GET['connected'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html__('Поврзано со Live Networks MCP.', 'ln-builder-connector'); ?></strong></p>
                </div>
            <?php elseif (isset($_GET['pending'])): ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html__('Се чека одобрување од админ на компанијата во панелот.', 'ln-builder-connector'); ?></p>
                </div>
            <?php elseif (isset($_GET['disconnected'])): ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html__('Успешно одјавено од Live Networks MCP.', 'ln-builder-connector'); ?></p>
                </div>
            <?php elseif (isset($_GET['reset'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Регистрацијата и статусот на сајтот се успешно ресетирани.', 'ln-builder-connector'); ?></p>
                </div>
            <?php elseif (!empty($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html(urldecode((string) $_GET['error'])); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($state['last_error']): ?>
                <div class="notice notice-error">
                    <p><strong><?php echo esc_html__('Последна грешка:', 'ln-builder-connector'); ?></strong> <?php echo esc_html($state['last_error']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($detected['builder'] === 'none'): ?>
                <div class="notice notice-warning">
                    <p><strong><?php echo esc_html__('Неподдржан или недостасува билдер:', 'ln-builder-connector'); ?></strong> <?php echo esc_html($detected['reason'] ?? 'Инсталирајте Bricks 2.2+ или Elementor Pro 3.x.'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($urlChanged): ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Адресата на сајтот е променета од поврзувањето. Кликнете на копчето за повторно поврзување.', 'ln-builder-connector'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($jwksStale): ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Внимание: JWKS клучевите не се освежени повеќе од 24 часа. Проверете ја врската со панелот.', 'ln-builder-connector'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="padding: 24px; margin-top: 20px;">
                <?php if ($status === PlatformState::STATUS_NOT_CONNECTED): ?>
                    <p style="font-size: 15px; margin-bottom: 20px;">
                        <?php echo esc_html__('Поврзете го вашиот сајт со Live Networks MCP за безбедна автоматизација и креирање страници.', 'ln-builder-connector'); ?>
                    </p>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row" style="width: 140px; color: #666;"><?php echo esc_html__('Site ID (UUID):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($siteId); ?></code></td>
                        </tr>
                    </table>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <form method="post" action="">
                            <?php wp_nonce_field('ln_builder_connect_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="connect" />
                            <button type="submit" class="button button-primary button-hero">
                                <?php echo esc_html__('Најави се на MCP', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сакате да генерирате нов Site ID (ресетирање на регистрацијата)?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_reset_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="reset_registration" />
                            <input type="hidden" name="regenerate_site_id" value="1" />
                            <button type="submit" class="button button-secondary">
                                <?php echo esc_html__('Ресетирај регистрација (нов Site ID)', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                    </div>

                <?php elseif ($status === PlatformState::STATUS_CONNECTING): ?>
                    <div style="margin-bottom: 20px;">
                        <span class="dashicons dashicons-update" style="color: #dba617; font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                        <strong style="font-size: 16px;">
                            <?php echo esc_html__('Поврзувањето е во тек...', 'ln-builder-connector'); ?>
                        </strong>
                        <p style="color: #666; margin-top: 8px;">
                            <?php echo esc_html__('Поврзувањето со панелот беше иницирано. Доколку најавата беше прекината или не успеа, можете да се обидете повторно или да ја ресетирате регистрацијата.', 'ln-builder-connector'); ?>
                        </p>
                    </div>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row" style="width: 140px; color: #666;"><?php echo esc_html__('Site ID (UUID):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($siteId); ?></code></td>
                        </tr>
                    </table>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <form method="post" action="">
                            <?php wp_nonce_field('ln_builder_connect_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="connect" />
                            <button type="submit" class="button button-primary">
                                <?php echo esc_html__('Обиди се повторно', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сакате да го откажете поврзувањето и да ја ресетирате состојбата?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_reset_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="reset_registration" />
                            <button type="submit" class="button button-secondary">
                                <?php echo esc_html__('Ресетирај регистрација', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                    </div>

                    <script>
                    (function() {
                        var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
                        var checkTimer = setInterval(function() {
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', ajaxUrl, true);
                            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                            xhr.onload = function() {
                                if (xhr.status === 200) {
                                    try {
                                        var res = JSON.parse(xhr.responseText);
                                        if (res && res.success && res.data) {
                                            if (res.data.status === 'connected' || res.data.status === 'pending_approval') {
                                                clearInterval(checkTimer);
                                                window.location.reload();
                                            }
                                        }
                                    } catch (e) {}
                                }
                            };
                            xhr.send('action=ln_builder_check_status');
                        }, 2500);
                    })();
                    </script>

                <?php elseif ($status === PlatformState::STATUS_PENDING_APPROVAL): ?>
                    <div style="margin-bottom: 20px;">
                        <span class="dashicons dashicons-clock" style="color: #dba617; font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                        <strong style="font-size: 16px;">
                            <?php echo esc_html__('Се чека одобрување од админ на компанијата', 'ln-builder-connector'); ?>
                        </strong>
                        <p style="color: #666; margin-top: 8px;">
                            <?php echo esc_html__('Сајтот беше поврзан од член на компанијата. Потребно е одобрување од администратор во панелот.', 'ln-builder-connector'); ?>
                        </p>
                    </div>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row" style="width: 140px; color: #666;"><?php echo esc_html__('Site ID (UUID):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($siteId); ?></code></td>
                        </tr>
                        <?php if (!empty($state['site_key'])): ?>
                            <tr>
                                <th scope="row" style="color: #666;"><?php echo esc_html__('Site Key:', 'ln-builder-connector'); ?></th>
                                <td><code><?php echo esc_html($state['site_key']); ?></code></td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <form method="post" action="" target="_blank">
                            <?php wp_nonce_field('ln_builder_connect_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="connect" />
                            <button type="submit" class="button button-secondary">
                                <?php echo esc_html__('Освежи статус', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сте сигурни дека сакате да ја ресетирате регистрацијата?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_reset_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="reset_registration" />
                            <button type="submit" class="button button-link-delete">
                                <?php echo esc_html__('Ресетирај регистрација', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                    </div>

                <?php elseif ($status === PlatformState::STATUS_ERROR): ?>
                    <p style="font-size: 15px; margin-bottom: 20px; color: #b32d2e;">
                        <?php echo esc_html__('Се појави грешка при поврзувањето со Live Networks MCP.', 'ln-builder-connector'); ?>
                    </p>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row" style="width: 140px; color: #666;"><?php echo esc_html__('Site ID (UUID):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($siteId); ?></code></td>
                        </tr>
                    </table>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <form method="post" action="" target="_blank">
                            <?php wp_nonce_field('ln_builder_connect_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="connect" />
                            <button type="submit" class="button button-primary">
                                <?php echo esc_html__('Обиди се повторно', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сакате да ја ресетирате состојбата на регистрацијата?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_reset_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="reset_registration" />
                            <button type="submit" class="button button-secondary">
                                <?php echo esc_html__('Ресетирај регистрација', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                    </div>

                <?php elseif ($status === PlatformState::STATUS_CONNECTED): ?>
                    <div style="margin-bottom: 20px;">
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                        <strong style="font-size: 18px; color: #46b450;">
                            <?php echo esc_html__('Поврзано', 'ln-builder-connector'); ?>
                        </strong>
                    </div>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row" style="width: 140px; color: #666;"><?php echo esc_html__('Site ID (UUID):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($siteId); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" style="color: #666;"><?php echo esc_html__('Site Key:', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($state['site_key']); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row" style="color: #666;"><?php echo esc_html__('Issuer (MCP):', 'ln-builder-connector'); ?></th>
                            <td><code><?php echo esc_html($state['iss']); ?></code></td>
                        </tr>
                        <?php if (!empty($state['connected_at'])): ?>
                            <tr>
                                <th scope="row" style="color: #666;"><?php echo esc_html__('Поврзано на:', 'ln-builder-connector'); ?></th>
                                <td><?php echo esc_html($state['connected_at']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сте сигурни дека сакате да го одјавите сајтот?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_disconnect_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="disconnect" />
                            <button type="submit" class="button button-link-delete">
                                <?php echo esc_html__('Одјави', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Дали сте сигурни дека сакате да ја ресетирате регистрацијата?', 'ln-builder-connector')); ?>');">
                            <?php wp_nonce_field('ln_builder_reset_action'); ?>
                            <input type="hidden" name="ln_builder_action" value="reset_registration" />
                            <button type="submit" class="button button-secondary">
                                <?php echo esc_html__('Ресетирај регистрација', 'ln-builder-connector'); ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <p style="color: #888; font-size: 12px; margin-top: 16px;">
                LN Builder Connector v<?php echo esc_html(LN_BUILDER_VERSION); ?> ·
                Contract v<?php echo esc_html(LN_BUILDER_CONTRACT_VERSION); ?> ·
                Builder: <?php echo esc_html($detected['builder'] . ' ' . $detected['version']); ?>
            </p>
        </div>
        <?php
    }

    public static function renderDevAuthNotice(): void
    {
        if (!Installer::isDevAuthEnabled()) {
            return;
        }

        $screen = get_current_screen();
        if ($screen !== null && $screen->id === 'toplevel_page_' . self::MENU_SLUG) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php echo esc_html__('ВНИМАНИЕ (Dev Auth):', 'ln-builder-connector'); ?></strong>
                    <?php echo esc_html__('Овозможена е развојна автентикација (LN_BUILDER_DEV_AUTH). Ова не смее да се користи во продукција.', 'ln-builder-connector'); ?>
                </p>
            </div>
            <?php
        }
    }

    public static function registerMetaBoxes(): void
    {
        $screens = ['page', 'bricks_template', 'elementor_library'];
        foreach ($screens as $screen) {
            add_meta_box(
                'ln_builder_meta_box',
                __('LN Builder Connector', 'ln-builder-connector'),
                [self::class, 'renderMetaBox'],
                $screen,
                'side',
                'default'
            );
        }
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        $createdBy = get_post_meta($post->ID, '_ln_builder_created_by', true);
        if (is_string($createdBy)) {
            $createdBy = json_decode($createdBy, true);
        }

        $lastAiWrite = get_post_meta($post->ID, '_ln_builder_last_ai_write', true);
        if (is_string($lastAiWrite)) {
            $lastAiWrite = json_decode($lastAiWrite, true);
        }

        $hasCreated = is_array($createdBy) && !empty($createdBy['act']);
        $hasLastWrite = is_array($lastAiWrite) && !empty($lastAiWrite['act']);

        $objType = ($post->post_type === 'bricks_template' || $post->post_type === 'elementor_library') ? 'template' : 'page';
        $auditUrl = add_query_arg([
            'page' => self::MENU_SLUG,
            'audit_object' => "{$objType}:{$post->ID}",
        ], admin_url('admin.php'));

        if (!$hasCreated && !$hasLastWrite) {
            echo '<p style="color:#666;font-size:12px;margin:0;">' . esc_html__('No AI builder modifications recorded for this item.', 'ln-builder-connector') . '</p>';
            return;
        }

        if ($hasCreated) {
            $authorName = !empty($createdBy['name']) ? (string) $createdBy['name'] : (string) ($createdBy['act'] ?? '');
            $date = !empty($createdBy['at']) ? (string) $createdBy['at'] : '—';
            echo '<div style="margin-bottom:12px;">';
            echo '<strong style="display:block;font-size:12px;margin-bottom:4px;">' . esc_html__('Created via AI:', 'ln-builder-connector') . '</strong>';
            echo '<div style="font-size:12px;line-height:1.5;">';
            echo '<strong>' . esc_html__('User:', 'ln-builder-connector') . '</strong> ' . esc_html($authorName) . '<br>';
            if (!empty($createdBy['org'])) {
                echo '<strong>' . esc_html__('Organization:', 'ln-builder-connector') . '</strong> ' . esc_html((string) $createdBy['org']) . '<br>';
            }
            echo '<strong>' . esc_html__('Date:', 'ln-builder-connector') . '</strong> <code>' . esc_html($date) . '</code>';
            echo '</div>';
            echo '</div>';
        }

        if ($hasLastWrite) {
            $writeAuthor = !empty($lastAiWrite['name']) ? (string) $lastAiWrite['name'] : (string) ($lastAiWrite['act'] ?? '');
            $writeDate = !empty($lastAiWrite['at']) ? (string) $lastAiWrite['at'] : '—';
            echo '<div style="margin-bottom:12px;">';
            echo '<strong style="display:block;font-size:12px;margin-bottom:4px;">' . esc_html__('Last AI Write:', 'ln-builder-connector') . '</strong>';
            echo '<div style="font-size:12px;line-height:1.5;">';
            echo '<strong>' . esc_html__('User:', 'ln-builder-connector') . '</strong> ' . esc_html($writeAuthor) . '<br>';
            if (!empty($lastAiWrite['org'])) {
                echo '<strong>' . esc_html__('Organization:', 'ln-builder-connector') . '</strong> ' . esc_html((string) $lastAiWrite['org']) . '<br>';
            }
            echo '<strong>' . esc_html__('Date:', 'ln-builder-connector') . '</strong> <code>' . esc_html($writeDate) . '</code>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div style="margin-top:12px;padding-top:8px;border-top:1px solid #ddd;">';
        echo '<a href="' . esc_url($auditUrl) . '" target="_blank" style="font-size:12px;text-decoration:none;">';
        echo esc_html__('View item audit log &rarr;', 'ln-builder-connector');
        echo '</a>';
        echo '</div>';
    }
}
