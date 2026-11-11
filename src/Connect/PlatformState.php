<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Connect;

final class PlatformState
{
    public const OPTION_PLATFORM = 'ln_builder_platform';

    public const STATUS_NOT_CONNECTED = 'not_connected';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';

    public const DEFAULT_PANEL_URL = 'https://wpconsole.live.net.mk';

    /**
     * @return array{
     *     status: string,
     *     panel_url: string,
     *     site_key: string,
     *     iss: string,
     *     jwks_uri: string,
     *     granted_scopes: string[],
     *     connected_at: string|null,
     *     connected_by: int|null,
     *     connected_url: string|null,
     *     last_error: string|null
     * }
     */
    public static function get(): array
    {
        $default = [
            'status' => self::STATUS_NOT_CONNECTED,
            'panel_url' => self::panelUrl(),
            'site_key' => '',
            'iss' => '',
            'jwks_uri' => '',
            'granted_scopes' => [],
            'connected_at' => null,
            'connected_by' => null,
            'connected_url' => null,
            'last_error' => null,
        ];

        if (!function_exists('get_option')) {
            return $default;
        }

        $stored = get_option(self::OPTION_PLATFORM, []);
        if (!is_array($stored)) {
            return $default;
        }

        return array_merge($default, $stored, [
            'panel_url' => self::panelUrl(), // constant override always wins
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function set(array $state): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        $current = self::get();
        $merged = array_merge($current, $state);
        update_option(self::OPTION_PLATFORM, $merged, true);
    }

    public static function panelUrl(): string
    {
        if (defined('LN_BUILDER_PANEL_URL') && is_string(LN_BUILDER_PANEL_URL) && LN_BUILDER_PANEL_URL !== '') {
            return rtrim(LN_BUILDER_PANEL_URL, '/');
        }

        if (function_exists('get_option')) {
            $stored = get_option(self::OPTION_PLATFORM, []);
            if (
                is_array($stored)
                && !empty($stored['panel_url'])
                && is_string($stored['panel_url'])
                && $stored['panel_url'] !== 'https://panel.example'
                && $stored['panel_url'] !== 'https://wpbridge.live.net.mk/mcp'
                && $stored['panel_url'] !== 'https://wpbridge.live.net.mk'
            ) {
                return rtrim($stored['panel_url'], '/');
            }
        }

        return self::DEFAULT_PANEL_URL;
    }

    public static function status(): string
    {
        return self::get()['status'] ?? self::STATUS_NOT_CONNECTED;
    }

    /**
     * Site is considered connected for auth verification when active or pending_approval.
     */
    public static function isConnected(): bool
    {
        $status = self::status();
        return $status === self::STATUS_CONNECTED || $status === self::STATUS_PENDING_APPROVAL;
    }

    public static function siteKey(): string
    {
        return (string) (self::get()['site_key'] ?? '');
    }

    public static function iss(): string
    {
        return (string) (self::get()['iss'] ?? '');
    }

    public static function jwksUri(): string
    {
        return (string) (self::get()['jwks_uri'] ?? '');
    }

    /**
     * @return string[]
     */
    public static function grantedScopes(): array
    {
        $scopes = self::get()['granted_scopes'] ?? [];
        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
    }

    public static function clear(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_PLATFORM);
        }
    }
}
