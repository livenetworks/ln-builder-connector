<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/ln-builder-bridge.php';

\LiveNetworks\BuilderBridge\Install\Installer::uninstall();
