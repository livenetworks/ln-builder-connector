<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector;

use LiveNetworks\BuilderConnector\Admin\AdminPage;
use LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder;
use LiveNetworks\BuilderConnector\Install\Installer;
use LiveNetworks\BuilderConnector\Rest\Responder;
use LiveNetworks\BuilderConnector\Rest\Router;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        Installer::registerHooks();
        DesignContextBuilder::registerInvalidationHooks();
        Responder::registerHooks();
        Router::registerHooks();
        AdminPage::registerHooks();
    }
}
