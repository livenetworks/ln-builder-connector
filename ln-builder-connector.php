<?php
/**
 * Plugin Name:       LN Builder Connector
 * Plugin URI:        https://livenetworks.mk/
 * Description:       Safe, authenticated REST connector over page-builder data (Bricks / Elementor) for AI clients and MCP.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Live Networks
 * Author URI:        https://livenetworks.mk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ln-builder-connector
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('LN_BUILDER_VERSION', '1.0.0');
define('LN_BUILDER_CONNECTOR_VERSION', '1.0.0');
define('LN_BUILDER_CONTRACT_VERSION', '0.4.0');
define('LN_BUILDER_DB_VERSION', '0.2.0');
define('LN_BUILDER_PLUGIN_FILE', __FILE__);
define('LN_BUILDER_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Built-in PSR-4 autoloader for LiveNetworks\BuilderConnector\ with legacy alias fallback
spl_autoload_register(static function (string $class): void {
    $connectorPrefix = 'LiveNetworks\\BuilderConnector\\';
    $bridgePrefix = 'LiveNetworks\\BuilderBridge\\';

    if (strncmp($connectorPrefix, $class, strlen($connectorPrefix)) === 0) {
        $relative = substr($class, strlen($connectorPrefix));
        $file = LN_BUILDER_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }

    if (strncmp($bridgePrefix, $class, strlen($bridgePrefix)) === 0) {
        $relative = substr($class, strlen($bridgePrefix));
        $file = LN_BUILDER_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        $connectorClass = $connectorPrefix . $relative;
        if (class_exists($connectorClass, false) && !class_exists($class, false)) {
            class_alias($connectorClass, $class);
        }
    }
});

// Load plugin text domain
add_action('init', static function (): void {
    load_plugin_textdomain(
        'ln-builder-connector',
        false,
        dirname(plugin_basename(LN_BUILDER_PLUGIN_FILE)) . '/languages'
    );
});

if (class_exists(\LiveNetworks\BuilderConnector\Plugin::class)) {
    \LiveNetworks\BuilderConnector\Plugin::boot();
} elseif (class_exists(\LiveNetworks\BuilderBridge\Plugin::class)) {
    \LiveNetworks\BuilderBridge\Plugin::boot();
}
