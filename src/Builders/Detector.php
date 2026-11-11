<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Builders;

use LiveNetworks\BuilderConnector\Builders\Bricks\BricksAdapter;
use LiveNetworks\BuilderConnector\Builders\Elementor\ElementorAdapter;

final class Detector
{
    public const MIN_BRICKS_VERSION = '2.2';

    /** @var array{adapter: ?BuilderAdapter, builder: ?string, version: ?string, reason: ?string}|null */
    private static ?array $cachedResult = null;

    /**
     * Detect the active page builder for the current site.
     *
     * @return array{adapter: ?BuilderAdapter, builder: ?string, version: ?string, reason: ?string}
     */
    public static function detect(): array
    {
        if (self::$cachedResult !== null) {
            return self::$cachedResult;
        }

        $theme = wp_get_theme();
        $parentTheme = $theme->parent();
        $themeName = $parentTheme ? (string) $parentTheme->get('Name') : (string) $theme->get('Name');
        $themeTemplate = $parentTheme ? (string) $parentTheme->get_stylesheet() : (string) $theme->get_stylesheet();
        $bricksVersion = $parentTheme ? (string) $parentTheme->get('Version') : (string) $theme->get('Version');

        $isBricksActive = (strtolower($themeName) === 'bricks' || strtolower($themeTemplate) === 'bricks' || str_starts_with(strtolower($themeTemplate), 'bricks'));
        $isElementorActive = defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
        $isElementorProActive = defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin');

        if ($isBricksActive && $isElementorActive) {
            return self::$cachedResult = [
                'adapter' => null,
                'builder' => null,
                'version' => null,
                'reason' => 'multiple_builders_active',
            ];
        }

        if ($isBricksActive) {
            if ($bricksVersion === '' && defined('BRICKS_VERSION')) {
                $bricksVersion = (string) BRICKS_VERSION;
            }
            if (version_compare($bricksVersion, self::MIN_BRICKS_VERSION, '<')) {
                return self::$cachedResult = [
                    'adapter' => null,
                    'builder' => null,
                    'version' => $bricksVersion,
                    'reason' => sprintf('bricks_version_unsupported (found %s, requires >= %s)', $bricksVersion, self::MIN_BRICKS_VERSION),
                ];
            }

            if (class_exists(BricksAdapter::class)) {
                $adapter = new BricksAdapter($bricksVersion);
                return self::$cachedResult = [
                    'adapter' => $adapter,
                    'builder' => $adapter->name(),
                    'version' => $adapter->version(),
                    'reason' => null,
                ];
            }

            return self::$cachedResult = [
                'adapter' => null,
                'builder' => 'bricks',
                'version' => $bricksVersion,
                'reason' => 'bricks_adapter_unavailable',
            ];
        }

        if ($isElementorActive) {
            $elVersion = defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : null;
            if (!$isElementorProActive) {
                return self::$cachedResult = [
                    'adapter' => null,
                    'builder' => 'elementor',
                    'version' => $elVersion,
                    'reason' => 'elementor_pro_required',
                ];
            }

            $proVersion = defined('ELEMENTOR_PRO_VERSION') ? (string) ELEMENTOR_PRO_VERSION : ($elVersion ?? '3.20.0');
            $adapter = new ElementorAdapter($proVersion);

            return self::$cachedResult = [
                'adapter' => $adapter,
                'builder' => $adapter->name(),
                'version' => $adapter->version(),
                'reason' => null,
            ];
        }

        return self::$cachedResult = [
            'adapter' => null,
            'builder' => null,
            'version' => null,
            'reason' => 'no_supported_builder_active',
        ];
    }

    public static function clearCache(): void
    {
        self::$cachedResult = null;
    }
}
