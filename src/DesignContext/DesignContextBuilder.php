<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\DesignContext;

use LiveNetworks\BuilderConnector\Builders\BuilderAdapter;
use LiveNetworks\BuilderConnector\Converter\Compactor;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;

final class DesignContextBuilder
{
    public const TRANSIENT_KEY = 'ln_builder_design_context_v1';
    public const CACHE_TTL = 3600;
    public const DEFAULT_COMPONENT_LIMIT = 15;

    public static function registerInvalidationHooks(): void
    {
        $optionHook = static function (string $optionName): void {
            if (
                str_starts_with($optionName, 'bricks_')
                || $optionName === MetaRepository::OPTION_META
                || $optionName === 'ln_builder_token_discipline'
            ) {
                self::invalidateCache();
            }
        };

        add_action('added_option', $optionHook, 10, 1);
        add_action('updated_option', $optionHook, 10, 1);
        add_action('deleted_option', $optionHook, 10, 1);

        add_action('save_post_bricks_template', [self::class, 'invalidateCache']);
        add_action('trashed_post', [self::class, 'invalidateCache']);
        add_action('deleted_post', [self::class, 'invalidateCache']);

        $metaHook = static function ($metaId, $objectId, string $metaKey): void {
            if (
                str_starts_with($metaKey, '_bricks_page_')
                || $metaKey === MetaRepository::POST_META_COMPONENT
                || $metaKey === '_bricks_template_type'
            ) {
                self::invalidateCache();
            }
        };

        add_action('added_post_meta', $metaHook, 10, 3);
        add_action('updated_post_meta', $metaHook, 10, 3);
        add_action('deleted_post_meta', $metaHook, 10, 3);
    }

    public static function invalidateCache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(BuilderAdapter $adapter, MetaRepository $metaRepo, bool $useCache = true): array
    {
        if ($useCache) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached) && isset($cached['builder'], $cached['tokens'], $cached['classes'])) {
                return $cached;
            }
        }

        $caps = $adapter->capabilities();
        $tokens = [
            'brand' => [],
            'scale' => [],
            'vocabulary' => [],
            'primitives' => [],
            'theme' => [],
        ];

        $seenTokenNames = [];

        foreach ($adapter->colors() as $color) {
            $colorName = (string) ($color['name'] ?? '');
            if ($colorName === '') {
                continue;
            }
            $cssVar = (string) ($color['css_var'] ?? '');
            if ($cssVar === '') {
                $rawField = (string) ($color['raw'] ?? '');
                if (preg_match('/^var\(\s*(--[a-zA-Z0-9_-]+)\s*\)$/', $rawField, $m)) {
                    $cssVar = $m[1];
                } else {
                    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $colorName));
                    $slug = trim($slug, '-');
                    $cssVar = str_starts_with($slug, 'brand-') ? '--' . $slug : '--brand-' . $slug;
                }
            }
            if (isset($seenTokenNames[$cssVar])) {
                continue;
            }
            $seenTokenNames[$cssVar] = true;
            $cMeta = $metaRepo->getColorMeta($colorName);
            $tokens['brand'][] = [
                'name' => $cssVar,
                'color_name' => $colorName,
                'role' => $cMeta['role'],
                'source' => 'builder',
            ];
        }

        foreach ($adapter->variables() as $var) {
            $name = (string) ($var['name'] ?? '');
            if ($name === '' || isset($seenTokenNames[$name])) {
                continue;
            }
            $seenTokenNames[$name] = true;
            $val = (string) ($var['value'] ?? '');
            $cat = (string) ($var['category'] ?? '');
            $vMeta = $metaRepo->getVariableMeta($name, $val, $cat);
            $layer = $vMeta['layer'];
            if (!isset($tokens[$layer])) {
                $layer = 'vocabulary';
            }

            $entry = [
                'name' => $name,
                'role' => $vMeta['role'],
                'source' => 'builder',
            ];
            $tokens[$layer][] = $entry;
        }

        if ((bool) get_option('ln_builder_include_theme_tokens', false) && function_exists('wp_get_global_settings')) {
            $paletteGroups = wp_get_global_settings(['color', 'palette']);
            if (is_array($paletteGroups)) {
                foreach ($paletteGroups as $groupItems) {
                    if (!is_array($groupItems)) {
                        continue;
                    }
                    foreach ($groupItems as $pItem) {
                        if (!is_array($pItem) || empty($pItem['slug'])) {
                            continue;
                        }
                        $tVar = '--wp--preset--color--' . (string) $pItem['slug'];
                        if (!isset($seenTokenNames[$tVar])) {
                            $seenTokenNames[$tVar] = true;
                            $tokens['theme'][] = [
                                'name' => $tVar,
                                'role' => (string) ($pItem['name'] ?? 'Theme color preset (read-only)'),
                                'source' => 'theme',
                            ];
                        }
                    }
                }
            }
        }

        $classes = [];
        foreach ($adapter->classes() as $cls) {
            $name = (string) ($cls['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $clsId = isset($cls['id']) && is_string($cls['id']) ? $cls['id'] : null;
            $cMeta = $metaRepo->getClassMeta($name, $clsId);
            $classes[] = [
                'name' => $name,
                'kind' => $cMeta['kind'],
                'purpose' => $cMeta['purpose'],
            ];
        }

        $components = $this->buildComponents($adapter, $metaRepo);
        $elements = array_keys($adapter->elementRegistry());

        $discipline = (string) get_option('ln_builder_token_discipline', 'strict');
        $rules = [
            'Use only classes and tokens listed here.',
        ];
        if ($discipline === 'strict') {
            $rules[] = 'No inline colors or sizes; reference CSS variables from tokens instead.';
            $rules[] = 'No inline style= or on*= attributes inside HTML text elements.';
        } elseif ($discipline === 'warn') {
            $rules[] = 'Prefer CSS variables over hardcoded colors or sizes (warnings emitted on hardcoded values).';
        }

        $capsArr = $caps->toArray();
        $result = [
            'builder' => $adapter->name(),
            'revision' => \LiveNetworks\BuilderConnector\Converter\Revision::compute([
                'tokens' => $tokens,
                'classes' => $classes,
                'rules' => $rules,
            ]),
            'capabilities' => [
                'global_classes' => $capsArr['global_classes'],
                'variables' => $capsArr['variables'],
                'areas' => $caps->areas(),
            ],
            'tokens' => $tokens,
            'classes' => $classes,
            'components' => $components,
            'elements' => $elements,
            'rules' => $rules,
        ];

        if ($useCache) {
            set_transient(self::TRANSIENT_KEY, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildComponents(BuilderAdapter $adapter, MetaRepository $metaRepo): array
    {
        $allTemplates = $adapter->templates(['per_page' => 100]);
        if ($allTemplates === []) {
            return [];
        }

        $matched = [];
        foreach ($allTemplates as $tpl) {
            $id = (int) ($tpl['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $compMeta = $metaRepo->getComponentMeta($id);
            if ($compMeta === null) {
                continue;
            }
            $matched[] = [
                'id' => $id,
                'slug' => (string) ($tpl['slug'] ?? ''),
                'title' => (string) ($tpl['title'] ?? ''),
                'type' => (string) ($tpl['type'] ?? 'section'),
                'meta' => $compMeta,
            ];
        }

        if ($matched === []) {
            return [];
        }

        $maxInline = max(1, (int) get_option('ln_builder_components_limit', 15));
        $includeFullDocument = count($matched) <= $maxInline;

        $vocab = $includeFullDocument ? $adapter->buildSiteVocabulary($metaRepo) : null;
        $schema = $includeFullDocument ? $adapter->getSchema() : [];
        $compactor = new Compactor();
        $components = [];

        foreach ($matched as $item) {
            $entry = [
                'id' => $item['id'],
                'template' => $item['slug'],
                'title' => $item['title'],
                'exposed_props' => $item['meta']['exposed_props'],
                'notes' => $item['meta']['notes'],
            ];

            if ($includeFullDocument && $vocab !== null) {
                $area = $adapter->areaForTemplateType($item['type']);
                $native = $adapter->contentArea($item['id'], $area);
                $compactResult = $compactor->compact($native, $area, $schema, $vocab);
                $entry['document'] = $compactResult->data();
            }

            $components[] = $entry;
        }

        return $components;
    }
}
