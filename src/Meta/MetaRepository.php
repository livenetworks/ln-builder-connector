<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Meta;

final class MetaRepository
{
    public const OPTION_META = 'ln_builder_meta';
    public const POST_META_COMPONENT = '_ln_builder_component';

    /**
     * @return array{
     *   classes: array<string, array{kind: string, purpose: string}>,
     *   variables: array<string, array{layer: string, role: string}>,
     *   colors: array<string, array{role: string}>
     * }
     */
    public function getAll(): array
    {
        $raw = get_option(self::OPTION_META, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'classes' => isset($raw['classes']) && is_array($raw['classes']) ? $raw['classes'] : [],
            'variables' => isset($raw['variables']) && is_array($raw['variables']) ? $raw['variables'] : [],
            'colors' => isset($raw['colors']) && is_array($raw['colors']) ? $raw['colors'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $all
     */
    public function replaceAll(array $all): void
    {
        $clean = [
            'classes' => isset($all['classes']) && is_array($all['classes']) ? $all['classes'] : [],
            'variables' => isset($all['variables']) && is_array($all['variables']) ? $all['variables'] : [],
            'colors' => isset($all['colors']) && is_array($all['colors']) ? $all['colors'] : [],
        ];
        update_option(self::OPTION_META, $clean, false);
    }

    /**
     * Lookup class metadata by builder class ID (preferred) or class name (fallback).
     *
     * @return array{kind: string, purpose: string}
     */
    public function getClassMeta(string $name, ?string $id = null): array
    {
        $all = $this->getAll();
        $entry = null;

        if ($id !== null && $id !== '' && isset($all['classes']['id:' . $id]) && is_array($all['classes']['id:' . $id])) {
            $entry = $all['classes']['id:' . $id];
        } elseif (isset($all['classes'][$name]) && is_array($all['classes'][$name])) {
            $entry = $all['classes'][$name];
        }

        if ($entry !== null) {
            $rawKind = (string) ($entry['kind'] ?? '');
            $kind = in_array($rawKind, ['component', 'island', 'base'], true) ? $rawKind : $this->inferClassKind($name);
            return [
                'kind' => $kind,
                'purpose' => (string) ($entry['purpose'] ?? ''),
            ];
        }

        return [
            'kind' => $this->inferClassKind($name),
            'purpose' => '',
        ];
    }

    public function setClassMeta(string $name, string $kind, string $purpose, ?string $id = null): void
    {
        $validKind = in_array($kind, ['component', 'island', 'base'], true) ? $kind : $this->inferClassKind($name);
        $cleanPurpose = substr(trim(sanitize_text_field($purpose)), 0, 200);

        $all = $this->getAll();
        $record = [
            'kind' => $validKind,
            'purpose' => $cleanPurpose,
        ];
        $all['classes'][$name] = $record;
        if ($id !== null && $id !== '') {
            $all['classes']['id:' . $id] = $record;
        }
        update_option(self::OPTION_META, $all, false);
    }

    public function removeClassMeta(string $name, ?string $id = null): void
    {
        $all = $this->getAll();
        unset($all['classes'][$name]);
        if ($id !== null && $id !== '') {
            unset($all['classes']['id:' . $id]);
        }
        update_option(self::OPTION_META, $all, false);
    }

    /**
     * @return array{layer: string, role: string}
     */
    public function getVariableMeta(string $name, string $value = '', string $category = ''): array
    {
        $normalized = str_starts_with($name, '--') ? $name : '--' . $name;
        $all = $this->getAll();

        if (isset($all['variables'][$normalized]) && is_array($all['variables'][$normalized])) {
            $rawLayer = (string) ($all['variables'][$normalized]['layer'] ?? '');
            if ($rawLayer === 'surface') {
                $rawLayer = 'vocabulary';
            }
            $layer = in_array($rawLayer, ['brand', 'scale', 'vocabulary', 'primitives', 'theme'], true)
                ? $rawLayer
                : $this->inferVariableLayer($normalized, $value, $category);
            return [
                'layer' => $layer,
                'role' => (string) ($all['variables'][$normalized]['role'] ?? ''),
            ];
        }

        return [
            'layer' => $this->inferVariableLayer($normalized, $value, $category),
            'role' => '',
        ];
    }

    public function setVariableMeta(string $name, string $layer, string $role): void
    {
        $normalized = str_starts_with($name, '--') ? $name : '--' . $name;
        if ($layer === 'surface') {
            $layer = 'vocabulary';
        }
        $cleanRole = substr(trim(sanitize_text_field($role)), 0, 200);

        $all = $this->getAll();
        $all['variables'][$normalized] = [
            'layer' => $layer,
            'role' => $cleanRole,
        ];
        update_option(self::OPTION_META, $all, false);
    }

    public function removeVariableMeta(string $name): void
    {
        $normalized = str_starts_with($name, '--') ? $name : '--' . $name;
        $all = $this->getAll();
        unset($all['variables'][$normalized]);
        update_option(self::OPTION_META, $all, false);
    }

    /**
     * @return array{role: string}
     */
    public function getColorMeta(string $name): array
    {
        $all = $this->getAll();
        if (isset($all['colors'][$name]) && is_array($all['colors'][$name])) {
            return [
                'role' => (string) ($all['colors'][$name]['role'] ?? ''),
            ];
        }

        return ['role' => ''];
    }

    public function setColorMeta(string $name, string $role): void
    {
        $cleanRole = substr(trim(sanitize_text_field($role)), 0, 200);
        $all = $this->getAll();
        $all['colors'][$name] = ['role' => $cleanRole];
        update_option(self::OPTION_META, $all, false);
    }

    public function removeColorMeta(string $name): void
    {
        $all = $this->getAll();
        unset($all['colors'][$name]);
        update_option(self::OPTION_META, $all, false);
    }

    /**
     * @return array{exposed_props: list<string>, notes: string}|null
     */
    public function getComponentMeta(int $postId): ?array
    {
        $raw = get_post_meta($postId, self::POST_META_COMPONENT, true);
        if ($raw === '1' || $raw === 1 || $raw === true) {
            return [
                'exposed_props' => [],
                'notes' => '',
            ];
        }
        if (!is_array($raw) || empty($raw)) {
            return null;
        }

        $props = [];
        if (isset($raw['exposed_props']) && is_array($raw['exposed_props'])) {
            foreach ($raw['exposed_props'] as $p) {
                if (is_string($p) && $p !== '') {
                    $props[] = $p;
                }
            }
        }

        return [
            'exposed_props' => $props,
            'notes' => isset($raw['notes']) && is_string($raw['notes']) ? $raw['notes'] : '',
        ];
    }

    /**
     * @param array{exposed_props?: list<string>, notes?: string}|null $meta
     */
    public function setComponentMeta(int $postId, ?array $meta): void
    {
        if ($meta === null) {
            delete_post_meta($postId, self::POST_META_COMPONENT);
            return;
        }

        update_post_meta($postId, self::POST_META_COMPONENT, [
            'enabled' => true,
            'exposed_props' => isset($meta['exposed_props']) && is_array($meta['exposed_props'])
                ? array_values(array_map('strval', $meta['exposed_props']))
                : [],
            'notes' => isset($meta['notes']) ? substr(trim(sanitize_text_field((string) $meta['notes'])), 0, 200) : '',
        ]);
    }

    public function inferClassKind(string $name): string
    {
        if (in_array($name, ['section', 'container'], true)) {
            return 'base';
        }
        if (str_starts_with($name, 'island-') || str_starts_with($name, 'theme-')) {
            return 'island';
        }
        return 'component';
    }

    public function inferVariableLayer(string $name, string $value, string $category): string
    {
        $catLower = strtolower(trim($category));
        if ($catLower === 'surface' || $catLower === 'vocabulary') {
            return 'vocabulary';
        }
        if (in_array($catLower, ['brand', 'scale', 'primitives', 'theme'], true)) {
            return $catLower;
        }
        if (str_starts_with($name, '--wp--preset--')) {
            return 'theme';
        }
        if (str_starts_with($name, '--brand-')) {
            return 'brand';
        }
        if (
            str_starts_with($name, '--space-')
            || str_starts_with($name, '--text-')
            || str_starts_with($name, '--line-')
            || str_starts_with($name, '--container-')
            || str_starts_with($name, '--radius-')
            || str_starts_with($name, '--shadow-')
        ) {
            return 'scale';
        }
        if (
            str_starts_with($name, '--color-')
            || str_starts_with($name, '--gap')
            || str_starts_with($name, '--padding-')
            || str_starts_with($name, '--section-padding-')
            || str_starts_with($name, '--font-size-')
            || str_starts_with($name, '--line-height-')
            || str_starts_with($name, '--max-width')
            || $name === '--radius'
        ) {
            return 'primitives';
        }
        if (str_contains($value, 'var(')) {
            return 'primitives';
        }
        return 'vocabulary';
    }
}
