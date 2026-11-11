<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Builders\Bricks;

use LiveNetworks\BuilderConnector\Builders\BuilderAdapter;
use LiveNetworks\BuilderConnector\Builders\Capabilities;
use LiveNetworks\BuilderConnector\Converter\Expander;
use LiveNetworks\BuilderConnector\Converter\Problem;
use LiveNetworks\BuilderConnector\Converter\Revision;
use LiveNetworks\BuilderConnector\Converter\SiteVocabulary;
use LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder;
use LiveNetworks\BuilderConnector\Install\Installer;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;

final class BricksAdapter implements BuilderAdapter
{
    public const META_CONTENT = '_bricks_page_content_2';
    public const META_HEADER = '_bricks_page_header_2';
    public const META_FOOTER = '_bricks_page_footer_2';
    public const META_EDITOR_MODE = '_bricks_editor_mode';
    public const META_TEMPLATE_TYPE = '_bricks_template_type';
    public const META_TEMPLATE_SETTINGS = '_bricks_template_settings';
    public const POST_TYPE_TEMPLATE = 'bricks_template';

    public const OPT_GLOBAL_CLASSES = 'bricks_global_classes';
    public const OPT_GLOBAL_VARIABLES = 'bricks_global_variables';
    public const OPT_GLOBAL_VARIABLES_CATEGORIES = 'bricks_global_variables_categories';
    public const OPT_COLOR_PALETTE = 'bricks_color_palette';
    public const OPT_THEME_STYLES = 'bricks_theme_styles';

    public const ALLOWED_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /** @var array<string, mixed>|null */
    private ?array $schemaCache = null;

    public function name(): string
    {
        return 'bricks';
    }

    public function version(): string
    {
        return defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : '';
    }

    public function capabilities(): Capabilities
    {
        $templateTypes = ['header', 'footer', 'section', 'content', 'popup', 'archive', 'search', 'error'];
        if (
            class_exists('\Bricks\Setup')
            && isset(\Bricks\Setup::$control_options['templateTypes'])
            && is_array(\Bricks\Setup::$control_options['templateTypes'])
        ) {
            foreach (array_keys(\Bricks\Setup::$control_options['templateTypes']) as $typeKey) {
                $typeKey = (string) $typeKey;
                if ($typeKey !== '' && !in_array($typeKey, $templateTypes, true)) {
                    $templateTypes[] = $typeKey;
                }
            }
        }

        return new Capabilities(
            globalClasses: 'native',
            variables: 'native',
            globalColors: 'native',
            globalFonts: 'native',
            areas: ['content', 'header', 'footer'],
            templateTypes: $templateTypes,
            displayConditions: 'read'
        );
    }

    public function areaForTemplateType(string $templateType): string
    {
        return match ($templateType) {
            'header' => 'header',
            'footer' => 'footer',
            default => 'content',
        };
    }

    public function metaKeyForArea(string $area): string
    {
        return match ($area) {
            'content' => self::META_CONTENT,
            'header' => self::META_HEADER,
            'footer' => self::META_FOOTER,
            default => throw new \InvalidArgumentException(sprintf("Unsupported Bricks content area '%s'.", $area)),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contentArea(int $postId, string $area): array
    {
        $metaKey = $this->metaKeyForArea($area);
        $raw = get_post_meta($postId, $metaKey, true);
        if (!is_array($raw)) {
            return [];
        }

        $elements = [];
        foreach ($raw as $item) {
            if (is_array($item) && isset($item['id'], $item['name'])) {
                $elements[] = $item;
            }
        }

        return $elements;
    }

    /**
     * @param array<int, array<string, mixed>> $native
     */
    public function putContentArea(int $postId, string $area, array $native): void
    {
        $metaKey = $this->metaKeyForArea($area);
        $prevUserId = get_current_user_id();
        $switchedUser = false;

        if ($prevUserId === 0) {
            $ownerId = Installer::contentOwnerId();
            if ($ownerId > 0) {
                wp_set_current_user($ownerId);
                $switchedUser = true;
            }
        }

        if (class_exists('\Bricks\Capabilities')) {
            \Bricks\Capabilities::$capabilities_set = false;
        }

        try {
            update_post_meta($postId, self::META_EDITOR_MODE, 'bricks');

            if ($native === []) {
                delete_post_meta($postId, $metaKey);
                return;
            }

            // wp_slash protects backslashes in CSS/JSON strings from update_post_meta's internal wp_unslash
            update_post_meta($postId, $metaKey, wp_slash(array_values($native)));
        } finally {
            if ($switchedUser) {
                wp_set_current_user($prevUserId);
                if (class_exists('\Bricks\Capabilities')) {
                    \Bricks\Capabilities::$capabilities_set = false;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function templates(array $filter): array
    {
        return $this->templatesWithCount($filter)['items'];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function templatesWithCount(array $filter): array
    {
        $page = max(1, (int) ($filter['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filter['per_page'] ?? 50)));

        $statusFilter = self::ALLOWED_POST_STATUSES;
        if (isset($filter['status']) && is_string($filter['status']) && $filter['status'] !== '') {
            if (!in_array($filter['status'], self::ALLOWED_POST_STATUSES, true)) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
            $statusFilter = [$filter['status']];
        }

        $queryArgs = [
            'post_type' => self::POST_TYPE_TEMPLATE,
            'post_status' => $statusFilter,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => false,
        ];

        if (isset($filter['search']) && is_string($filter['search']) && trim($filter['search']) !== '') {
            $queryArgs['s'] = trim($filter['search']);
        }

        $metaQuery = [];
        if (isset($filter['type']) && is_string($filter['type']) && trim($filter['type']) !== '') {
            $metaQuery[] = [
                'key' => self::META_TEMPLATE_TYPE,
                'value' => trim($filter['type']),
                'compare' => '=',
            ];
        }

        if (isset($filter['component']) && $filter['component'] !== null && $filter['component'] !== '') {
            $isComp = filter_var($filter['component'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isComp === true || $filter['component'] === '1' || $filter['component'] === 1) {
                $metaQuery[] = [
                    'key' => '_ln_builder_component',
                    'value' => '1',
                    'compare' => '=',
                ];
            } elseif ($isComp === false || $filter['component'] === '0' || $filter['component'] === 0) {
                $metaQuery[] = [
                    'relation' => 'OR',
                    [
                        'key' => '_ln_builder_component',
                        'value' => '1',
                        'compare' => '!=',
                    ],
                    [
                        'key' => '_ln_builder_component',
                        'compare' => 'NOT EXISTS',
                    ],
                ];
            }
        }

        if ($metaQuery !== []) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $items[] = $this->formatTemplateSummary($post);
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function templateDetails(int $postId): ?array
    {
        $post = get_post($postId);
        if (
            !$post instanceof \WP_Post
            || $post->post_type !== self::POST_TYPE_TEMPLATE
            || !in_array($post->post_status, self::ALLOWED_POST_STATUSES, true)
        ) {
            return null;
        }

        $summary = $this->formatTemplateSummary($post);
        $settings = get_post_meta($postId, self::META_TEMPLATE_SETTINGS, true);
        $conditions = [];
        if (is_array($settings) && isset($settings['templateConditions']) && is_array($settings['templateConditions'])) {
            $conditions = array_values($settings['templateConditions']);
        }

        $summary['edit_url'] = $this->editLink($postId);
        $summary['display_conditions'] = $conditions;

        return $summary;
    }

    public function templateType(int $postId): ?string
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE_TEMPLATE) {
            return null;
        }

        $type = get_post_meta($postId, self::META_TEMPLATE_TYPE, true);
        return is_string($type) && $type !== '' ? $type : 'content';
    }

    public function findTemplateId($idOrSlug): ?int
    {
        if (is_int($idOrSlug) || (is_string($idOrSlug) && ctype_digit($idOrSlug))) {
            $id = (int) $idOrSlug;
            if ($id > 0 && $this->templateDetails($id) !== null) {
                return $id;
            }
            return null;
        }

        if (!is_string($idOrSlug) || trim($idOrSlug) === '') {
            return null;
        }

        $slug = sanitize_title(trim($idOrSlug));
        if ($slug === '') {
            return null;
        }

        $query = new \WP_Query([
            'post_type' => self::POST_TYPE_TEMPLATE,
            'name' => $slug,
            'post_status' => self::ALLOWED_POST_STATUSES,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!empty($query->posts[0])) {
            return (int) $query->posts[0];
        }

        return null;
    }

    public function createTemplate(string $title, ?string $slug, string $type): int
    {
        $desiredSlug = $slug !== null && trim($slug) !== '' ? sanitize_title($slug) : sanitize_title($title);
        if ($desiredSlug === '') {
            $desiredSlug = 'template';
        }
        $uniqueSlug = wp_unique_post_slug($desiredSlug, 0, 'draft', self::POST_TYPE_TEMPLATE, 0);

        $postId = wp_insert_post([
            'post_type' => self::POST_TYPE_TEMPLATE,
            'post_title' => $title,
            'post_name' => $uniqueSlug,
            'post_status' => 'draft',
            'post_author' => Installer::contentOwnerId(),
        ], true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create Bricks template: ' . $postId->get_error_message());
        }

        update_post_meta((int) $postId, self::META_TEMPLATE_TYPE, $type);
        update_post_meta((int) $postId, self::META_EDITOR_MODE, 'bricks');

        return (int) $postId;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function pages(array $filter): array
    {
        return $this->pagesWithCount($filter)['items'];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function pagesWithCount(array $filter): array
    {
        $page = max(1, (int) ($filter['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filter['per_page'] ?? 50)));

        $statusFilter = self::ALLOWED_POST_STATUSES;
        if (isset($filter['status']) && is_string($filter['status']) && $filter['status'] !== '') {
            if (!in_array($filter['status'], self::ALLOWED_POST_STATUSES, true)) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
            $statusFilter = [$filter['status']];
        }

        $queryArgs = [
            'post_type' => 'page',
            'post_status' => $statusFilter,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => false,
        ];

        if (isset($filter['search']) && is_string($filter['search']) && trim($filter['search']) !== '') {
            $queryArgs['s'] = trim($filter['search']);
        }

        if (isset($filter['parent']) && $filter['parent'] !== '' && $filter['parent'] !== null) {
            $queryArgs['post_parent'] = max(0, (int) $filter['parent']);
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $items[] = $this->formatPageSummary($post);
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pageDetails(int $postId): ?array
    {
        $post = get_post($postId);
        if (
            !$post instanceof \WP_Post
            || $post->post_type !== 'page'
            || !in_array($post->post_status, self::ALLOWED_POST_STATUSES, true)
        ) {
            return null;
        }

        $summary = $this->formatPageSummary($post);
        $permalink = get_permalink($postId);
        $summary['url'] = is_string($permalink) ? $permalink : '';
        $summary['edit_url'] = $this->editLink($postId);

        return $summary;
    }

    public function createPage(string $title, ?string $slug, ?int $parent): int
    {
        $desiredSlug = $slug !== null && trim($slug) !== '' ? sanitize_title($slug) : sanitize_title($title);
        if ($desiredSlug === '') {
            $desiredSlug = 'page';
        }
        $parentId = $parent !== null && $parent > 0 ? $parent : 0;
        $uniqueSlug = wp_unique_post_slug($desiredSlug, 0, 'draft', 'page', $parentId);

        $postId = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $title,
            'post_name' => $uniqueSlug,
            'post_parent' => $parentId,
            'post_status' => 'draft',
            'post_author' => Installer::contentOwnerId(),
        ], true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create page: ' . $postId->get_error_message());
        }

        update_post_meta((int) $postId, self::META_EDITOR_MODE, 'bricks');

        return (int) $postId;
    }

    public function publish(int $postId): void
    {
        $post = get_post($postId);
        $updateArgs = [
            'ID' => $postId,
            'post_status' => 'publish',
        ];

        // If scheduled in the future, reset post_date to now so WP transitions it to 'publish' instead of keeping 'future'
        if ($post instanceof \WP_Post && $post->post_status === 'future') {
            $updateArgs['post_date'] = current_time('mysql');
            $updateArgs['post_date_gmt'] = current_time('mysql', true);
            $updateArgs['edit_date'] = true;
        }

        $res = wp_update_post($updateArgs, true);

        if (is_wp_error($res)) {
            throw new \RuntimeException('Failed to publish post: ' . $res->get_error_message());
        }
    }

    public function editLink(int $postId): string
    {
        if (class_exists('\Bricks\Helpers') && method_exists('\Bricks\Helpers', 'get_builder_edit_link')) {
            $link = \Bricks\Helpers::get_builder_edit_link($postId);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        $permalink = get_permalink($postId);
        $base = is_string($permalink) && $permalink !== '' ? $permalink : home_url('/?p=' . $postId);
        return (string) add_query_arg('bricks', 'run', $base);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function classes(): array
    {
        $raw = get_option(self::OPT_GLOBAL_CLASSES, []);
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'])) {
                continue;
            }
            $id = (string) $item['id'];
            $name = (string) $item['name'];
            if ($id === '' || $name === '') {
                continue;
            }
            $result[] = [
                'id' => $id,
                'name' => $name,
                'settings' => isset($item['settings']) && is_array($item['settings']) ? $item['settings'] : [],
                'category' => isset($item['category']) ? (string) $item['category'] : '',
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $classes
     */
    public function putClasses(array $classes): void
    {
        update_option(self::OPT_GLOBAL_CLASSES, array_values($classes));
        update_option('bricks_global_classes_timestamp', time());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function variables(): array
    {
        $raw = get_option(self::OPT_GLOBAL_VARIABLES, []);
        if (!is_array($raw)) {
            return [];
        }

        $catRaw = get_option(self::OPT_GLOBAL_VARIABLES_CATEGORIES, []);
        $categoryMap = [];
        if (is_array($catRaw)) {
            foreach ($catRaw as $cat) {
                if (is_array($cat) && isset($cat['id'], $cat['name'])) {
                    $categoryMap[(string) $cat['id']] = (string) $cat['name'];
                }
            }
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }
            $rawName = trim((string) $item['name']);
            if ($rawName === '') {
                continue;
            }
            $normalizedName = str_starts_with($rawName, '--') ? $rawName : '--' . $rawName;
            $catId = isset($item['category']) ? (string) $item['category'] : '';
            $catName = $categoryMap[$catId] ?? $catId;

            $result[] = [
                'id' => isset($item['id']) ? (string) $item['id'] : $rawName,
                'name' => $normalizedName,
                'value' => isset($item['value']) ? (string) $item['value'] : '',
                'category' => $catName,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $variables
     */
    public function putVariables(array $variables): void
    {
        $normalized = [];
        foreach ($variables as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }
            $name = ltrim((string) $item['name'], '-');
            $item['name'] = $name;
            $normalized[] = $item;
        }
        update_option(self::OPT_GLOBAL_VARIABLES, $normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function colors(): array
    {
        $raw = get_option(self::OPT_COLOR_PALETTE, []);
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $palette) {
            if (!is_array($palette) || !isset($palette['colors']) || !is_array($palette['colors'])) {
                continue;
            }
            $paletteName = isset($palette['name']) ? (string) $palette['name'] : 'Default';
            foreach ($palette['colors'] as $color) {
                if (!is_array($color) || !isset($color['id'])) {
                    continue;
                }
                $id = (string) $color['id'];
                $name = isset($color['name']) && (string) $color['name'] !== '' ? (string) $color['name'] : $id;
                $result[] = [
                    'id' => $id,
                    'name' => $name,
                    'hex' => isset($color['hex']) ? (string) $color['hex'] : '',
                    'raw' => isset($color['raw']) ? (string) $color['raw'] : '',
                    'palette' => $paletteName,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $colors
     */
    public function putColors(array $colors): void
    {
        update_option(self::OPT_COLOR_PALETTE, $colors);
    }

    /**
     * @return array<string, mixed>
     */
    public function baseStyles(): array
    {
        $raw = get_option(self::OPT_THEME_STYLES, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $styles
     */
    public function putBaseStyles(string $name, array $styles): void
    {
        $all = $this->baseStyles();
        $all[$name] = $styles;
        update_option(self::OPT_THEME_STYLES, $all);
    }

    /**
     * Build the full normalized lean design-system response for `GET /design-system` (Contract §5.5 / Plugin §8.2).
     * Never exposes internal Bricks IDs.
     *
     * @return array<string, mixed>
     */
    public function designSystem(MetaRepository $metaRepo): array
    {
        $state = $this->designValidationState($metaRepo);

        $leanColors = [];
        foreach ($state['colors'] as $c) {
            $leanColors[] = [
                'name' => (string) $c['name'],
                'css_var' => (string) $c['css_var'],
                'value' => (string) $c['value'],
                'role' => (string) $c['role'],
            ];
        }

        $leanVariables = [];
        $typography = [];
        $spacing = [];
        foreach ($state['variables'] as $v) {
            $entry = [
                'name' => (string) $v['name'],
                'value' => (string) $v['value'],
                'layer' => (string) $v['layer'],
                'role' => (string) $v['role'],
            ];
            $leanVariables[] = $entry;

            if ($v['layer'] === 'scale') {
                $vName = (string) $v['name'];
                if (str_starts_with($vName, '--text-') || str_starts_with($vName, '--line-') || str_starts_with($vName, '--font-')) {
                    $typography[] = $entry;
                } elseif (str_starts_with($vName, '--space-') || str_starts_with($vName, '--radius') || str_starts_with($vName, '--container-')) {
                    $spacing[] = $entry;
                }
            }
        }

        $leanClasses = [];
        foreach ($state['classes'] as $cls) {
            $cssObj = [
                'declarations' => $cls['declarations'],
            ];
            if (!empty($cls['custom_css'])) {
                $cssObj['custom_css'] = (string) $cls['custom_css'];
            }
            $leanClasses[] = [
                'name' => (string) $cls['name'],
                'kind' => (string) $cls['kind'],
                'purpose' => (string) $cls['purpose'],
                'css' => $cssObj,
            ];
        }

        $rawThemeStyles = $this->baseStyles();
        $leanThemeStyles = [];
        foreach ($rawThemeStyles as $styleKey => $styleData) {
            if (!is_array($styleData)) {
                continue;
            }
            $settings = isset($styleData['settings']) && is_array($styleData['settings']) ? $styleData['settings'] : [];
            unset($settings['conditions']);
            $leanThemeStyles[(string) $styleKey] = [
                'name' => (string) $styleKey,
                'label' => isset($styleData['label']) ? (string) $styleData['label'] : (string) $styleKey,
                'settings' => $settings,
            ];
        }

        $revisions = [
            'colors' => Revision::compute($leanColors),
            'variables' => Revision::compute($leanVariables),
            'classes' => Revision::compute($leanClasses),
            'theme_styles' => Revision::compute($leanThemeStyles),
        ];

        return [
            'builder' => 'bricks',
            'revision' => Revision::compute($revisions),
            'revisions' => $revisions,
            'colors' => $leanColors,
            'variables' => $leanVariables,
            'classes' => $leanClasses,
            'theme_styles' => $leanThemeStyles,
            'typography' => $typography,
            'spacing' => $spacing,
        ];
    }

    /**
     * Build internal state map (`colors`, `variables`, `classes`, `theme_tokens`) for `DesignValidator`.
     * Retains `_raw` entries so untouched items can be saved back byte-for-byte.
     *
     * @return array<string, mixed>
     */
    public function designValidationState(MetaRepository $metaRepo): array
    {
        $colorsMap = [];
        $rawPalettes = get_option(self::OPT_COLOR_PALETTE, []);
        if (is_array($rawPalettes)) {
            foreach ($rawPalettes as $pIdx => $palette) {
                if (!is_array($palette) || !isset($palette['colors']) || !is_array($palette['colors'])) {
                    continue;
                }
                foreach ($palette['colors'] as $c) {
                    if (!is_array($c) || !isset($c['id'])) {
                        continue;
                    }
                    $id = (string) $c['id'];
                    $name = isset($c['name']) && trim((string) $c['name']) !== '' ? trim((string) $c['name']) : $id;
                    $rawField = isset($c['raw']) ? trim((string) $c['raw']) : '';
                    $hexField = isset($c['hex']) ? trim((string) $c['hex']) : '';

                    $cssVar = '';
                    if (preg_match('/^var\(\s*(--[a-zA-Z0-9_-]+)\s*\)$/', $rawField, $m)) {
                        $cssVar = $m[1];
                    } else {
                        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
                        $slug = trim($slug, '-');
                        $cssVar = str_starts_with($slug, 'brand-') ? '--' . $slug : '--brand-' . $slug;
                    }

                    $value = $hexField !== '' ? $hexField : ($rawField !== '' ? $rawField : '#000000');
                    $cMeta = $metaRepo->getColorMeta($name);

                    $colorsMap[$name] = [
                        'id' => $id,
                        'name' => $name,
                        'css_var' => $cssVar,
                        'value' => $value,
                        'role' => $cMeta['role'],
                        '_palette_idx' => $pIdx,
                        '_raw' => $c,
                    ];
                }
            }
        }

        $catRaw = get_option(self::OPT_GLOBAL_VARIABLES_CATEGORIES, []);
        $categoryMap = [];
        if (is_array($catRaw)) {
            foreach ($catRaw as $cat) {
                if (is_array($cat) && isset($cat['id'], $cat['name'])) {
                    $categoryMap[(string) $cat['id']] = (string) $cat['name'];
                }
            }
        }

        $variablesMap = [];
        $rawVars = get_option(self::OPT_GLOBAL_VARIABLES, []);
        if (is_array($rawVars)) {
            foreach ($rawVars as $v) {
                if (!is_array($v) || !isset($v['name'])) {
                    continue;
                }
                $rawName = trim((string) $v['name']);
                if ($rawName === '') {
                    continue;
                }
                $normName = str_starts_with($rawName, '--') ? $rawName : '--' . $rawName;
                $val = isset($v['value']) ? (string) $v['value'] : '';
                $catId = isset($v['category']) ? (string) $v['category'] : '';
                $catName = $categoryMap[$catId] ?? $catId;
                $vMeta = $metaRepo->getVariableMeta($normName, $val, $catName);

                $variablesMap[$normName] = [
                    'id' => isset($v['id']) ? (string) $v['id'] : $rawName,
                    'name' => $normName,
                    'value' => $val,
                    'layer' => $vMeta['layer'],
                    'role' => $vMeta['role'],
                    '_raw' => $v,
                ];
            }
        }

        $classesMap = [];
        $rawClasses = get_option(self::OPT_GLOBAL_CLASSES, []);
        if (is_array($rawClasses)) {
            foreach ($rawClasses as $cls) {
                if (!is_array($cls) || !isset($cls['id'], $cls['name'])) {
                    continue;
                }
                $id = (string) $cls['id'];
                $name = trim((string) $cls['name']);
                if ($name === '') {
                    continue;
                }
                $settings = isset($cls['settings']) && is_array($cls['settings']) ? $cls['settings'] : [];
                $rawCssCustom = isset($settings['_cssCustom']) && is_string($settings['_cssCustom']) ? $settings['_cssCustom'] : '';
                $parsedCss = $this->parseClassCssCustom($rawCssCustom, $name);
                $cMeta = $metaRepo->getClassMeta($name, $id);

                $classesMap[$name] = [
                    'id' => $id,
                    'name' => $name,
                    'kind' => $cMeta['kind'],
                    'purpose' => $cMeta['purpose'],
                    'declarations' => $parsedCss['declarations'],
                    'custom_css' => $parsedCss['custom_css'],
                    '_raw' => $cls,
                ];
            }
        }

        $themeTokens = ['--wp--preset--color--base', '--wp--preset--color--contrast'];
        if (function_exists('wp_get_global_settings')) {
            $paletteGroups = wp_get_global_settings(['color', 'palette']);
            if (is_array($paletteGroups)) {
                foreach ($paletteGroups as $groupItems) {
                    if (!is_array($groupItems)) {
                        continue;
                    }
                    foreach ($groupItems as $pItem) {
                        if (is_array($pItem) && !empty($pItem['slug'])) {
                            $themeTokens[] = '--wp--preset--color--' . (string) $pItem['slug'];
                        }
                    }
                }
            }
        }

        return [
            'colors' => $colorsMap,
            'variables' => $variablesMap,
            'classes' => $classesMap,
            'theme_tokens' => array_values(array_unique($themeTokens)),
        ];
    }

    /**
     * Find usages of a class name or CSS variable across pages and templates (capped at 100 posts for fast execution).
     *
     * @param 'class'|'variable' $type
     * @return list<string>
     */
    public function findDesignUsagesInContent(string $type, string $identifier): array
    {
        global $wpdb;

        $needle = $identifier;
        if ($type === 'class') {
            $classId = null;
            foreach ($this->classes() as $c) {
                if (($c['name'] ?? '') === $identifier) {
                    $classId = (string) ($c['id'] ?? '');
                    break;
                }
            }
            if ($classId === null || $classId === '') {
                return [];
            }
            $needle = $classId;
        }

        $like = '%' . $wpdb->esc_like($needle) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_type FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN (%s, %s, %s)
               AND pm.meta_value LIKE %s
               AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'private')
             LIMIT 20",
            self::META_CONTENT,
            self::META_HEADER,
            self::META_FOOTER,
            $like
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $usages = [];
        foreach ($rows as $r) {
            $pType = ((string) ($r['post_type'] ?? '')) === self::POST_TYPE_TEMPLATE ? 'template' : 'page';
            $usages[] = $pType . ':' . (int) ($r['ID'] ?? 0);
        }

        return array_values(array_unique($usages));
    }

    /**
     * Capture a lossless raw snapshot of the builder's design-system options.
     * Records `exists` so `restoreRawDesignSnapshot()` can delete options that were originally absent.
     *
     * @return array<string, mixed>
     */
    public function rawDesignSnapshot(string $area = 'preset'): array
    {
        $optionNames = match ($area) {
            'colors' => [self::OPT_COLOR_PALETTE],
            'variables' => [self::OPT_GLOBAL_VARIABLES, self::OPT_GLOBAL_VARIABLES_CATEGORIES],
            'classes' => [
                self::OPT_GLOBAL_CLASSES,
                'bricks_global_classes_categories',
                'bricks_global_classes_trash',
            ],
            'theme-styles' => [self::OPT_THEME_STYLES],
            default => [
                self::OPT_COLOR_PALETTE,
                self::OPT_GLOBAL_VARIABLES,
                self::OPT_GLOBAL_VARIABLES_CATEGORIES,
                self::OPT_GLOBAL_CLASSES,
                'bricks_global_classes_categories',
                'bricks_global_classes_trash',
                self::OPT_THEME_STYLES,
            ],
        };

        $sentinel = '__ln_builder_option_absent__';
        $options = [];
        foreach ($optionNames as $opt) {
            $val = get_option($opt, $sentinel);
            $options[$opt] = [
                'exists' => $val !== $sentinel,
                'value' => $val !== $sentinel ? $val : null,
            ];
        }

        // Snapshot only the relevant slice of MetaRepository to prevent overwriting other areas on restore
        $rawMeta = get_option(MetaRepository::OPTION_META, []);
        $metaSlice = is_array($rawMeta) ? match ($area) {
            'colors' => ['colors' => $rawMeta['colors'] ?? []],
            'variables' => ['variables' => $rawMeta['variables'] ?? []],
            'classes' => ['classes' => $rawMeta['classes'] ?? []],
            'theme-styles' => [],
            default => $rawMeta,
        } : [];

        return [
            'builder' => 'bricks',
            'area' => $area,
            'options' => $options,
            'meta_slice' => $metaSlice,
        ];
    }

    /**
     * Restore a raw snapshot previously captured by `rawDesignSnapshot()`.
     *
     * @param array<string, mixed> $snapshot
     */
    public function restoreRawDesignSnapshot(array $snapshot): void
    {
        $options = isset($snapshot['options']) && is_array($snapshot['options']) ? $snapshot['options'] : [];
        foreach ($options as $optName => $spec) {
            if (!is_string($optName) || !is_array($spec)) {
                continue;
            }
            $exists = !empty($spec['exists']);
            if (!$exists) {
                delete_option($optName);
            } else {
                update_option($optName, $spec['value']);
            }
        }

        // Restore metadata slice without wiping other design areas
        if (isset($snapshot['meta_slice']) && is_array($snapshot['meta_slice'])) {
            $currentMeta = get_option(MetaRepository::OPTION_META, []);
            if (!is_array($currentMeta)) {
                $currentMeta = [];
            }
            $area = isset($snapshot['area']) && is_string($snapshot['area']) ? $snapshot['area'] : 'preset';
            if ($area === 'preset') {
                update_option(MetaRepository::OPTION_META, $snapshot['meta_slice']);
            } else {
                foreach ($snapshot['meta_slice'] as $k => $sliceData) {
                    $currentMeta[$k] = $sliceData;
                }
                update_option(MetaRepository::OPTION_META, $currentMeta);
            }
        }

        if (isset($options[self::OPT_GLOBAL_CLASSES])) {
            update_option('bricks_global_classes_timestamp', time());
        }

        $area = isset($snapshot['area']) && is_string($snapshot['area']) ? $snapshot['area'] : 'preset';
        $this->afterDesignWrite($area);
    }

    /**
     * Persist validated merged colors and their `MetaRepository` roles.
     *
     * @param array<string, array<string, mixed>> $mergedColors
     */
    public function saveMergedColors(array $mergedColors, MetaRepository $metaRepo): void
    {
        $rawPalettes = get_option(self::OPT_COLOR_PALETTE, []);
        if (!is_array($rawPalettes) || $rawPalettes === [] || !isset($rawPalettes[0]['colors']) || !is_array($rawPalettes[0]['colors'])) {
            $rawPalettes = [
                [
                    'id' => Expander::defaultIdGenerator(),
                    'name' => 'Default',
                    'colors' => [],
                ],
            ];
        }

        $usedIds = [];
        foreach ($rawPalettes as $p) {
            if (isset($p['id']) && is_string($p['id'])) {
                $usedIds[$p['id']] = true;
            }
            foreach (($p['colors'] ?? []) as $c) {
                if (is_array($c) && isset($c['id']) && is_string($c['id'])) {
                    $usedIds[$c['id']] = true;
                }
            }
        }

        $metaAll = $metaRepo->getAll();
        $metaColors = [];
        $newPaletteColors = [];

        foreach ($mergedColors as $cSpec) {
            $name = (string) $cSpec['name'];
            $cssVar = (string) $cSpec['css_var'];
            $value = (string) $cSpec['value'];
            $role = (string) ($cSpec['role'] ?? '');

            $metaColors[$name] = ['role' => substr(trim(sanitize_text_field($role)), 0, 200)];

            $rawEntry = isset($cSpec['_raw']) && is_array($cSpec['_raw']) ? $cSpec['_raw'] : null;
            if ($rawEntry !== null) {
                $oldHex = isset($rawEntry['hex']) ? (string) $rawEntry['hex'] : '';
                $oldRaw = isset($rawEntry['raw']) ? (string) $rawEntry['raw'] : '';
                if (($oldHex === $value || ($oldHex === '' && $oldRaw === $value)) && ($oldRaw === "var({$cssVar})" || $oldRaw !== '')) {
                    // If value wasn't modified, keep the original native entry byte-for-byte
                    if ($oldHex === $value && ($oldRaw === "var({$cssVar})" || !isset($cSpec['_modified']))) {
                        $newPaletteColors[] = $rawEntry;
                        continue;
                    }
                }
                $entry = $rawEntry;
                $entry['name'] = $name;
                $entry['hex'] = $value;
                $entry['raw'] = "var({$cssVar})";
                $newPaletteColors[] = $entry;
                continue;
            }

            $id = $this->generateUniqueDesignId($usedIds);
            $newPaletteColors[] = [
                'id' => $id,
                'name' => $name,
                'hex' => $value,
                'raw' => "var({$cssVar})",
            ];
        }

        $rawPalettes[0]['colors'] = $newPaletteColors;
        update_option(self::OPT_COLOR_PALETTE, $rawPalettes);

        $metaAll['colors'] = $metaColors;
        $metaRepo->replaceAll($metaAll);
    }

    /**
     * Persist validated merged variables, their Bricks categories, and their `MetaRepository` layer/role.
     *
     * @param array<string, array<string, mixed>> $mergedVariables
     */
    public function saveMergedVariables(array $mergedVariables, MetaRepository $metaRepo): void
    {
        $rawCats = get_option(self::OPT_GLOBAL_VARIABLES_CATEGORIES, []);
        if (!is_array($rawCats)) {
            $rawCats = [];
        }

        $usedIds = [];
        $catIdByName = [];
        foreach ($rawCats as $cat) {
            if (is_array($cat) && isset($cat['id'], $cat['name'])) {
                $cid = (string) $cat['id'];
                $cname = strtolower(trim((string) $cat['name']));
                $usedIds[$cid] = true;
                $catIdByName[$cname] = $cid;
            }
        }

        foreach ($mergedVariables as $vSpec) {
            if (isset($vSpec['id']) && is_string($vSpec['id'])) {
                $usedIds[$vSpec['id']] = true;
            }
        }

        $catsModified = false;
        $ensureCategory = function (string $layer) use (&$rawCats, &$catIdByName, &$usedIds, &$catsModified): string {
            // Overview §6.1: vocabulary layer maps to Bricks category 'surface'
            $bricksCatName = $layer === 'vocabulary' ? 'surface' : $layer;
            if (isset($catIdByName[$bricksCatName])) {
                return $catIdByName[$bricksCatName];
            }
            if ($layer === 'vocabulary' && isset($catIdByName['vocabulary'])) {
                return $catIdByName['vocabulary'];
            }
            $newCatId = $this->generateUniqueDesignId($usedIds);
            $rawCats[] = [
                'id' => $newCatId,
                'name' => $bricksCatName,
            ];
            $catIdByName[$bricksCatName] = $newCatId;
            $catsModified = true;
            return $newCatId;
        };

        $metaAll = $metaRepo->getAll();
        $metaVars = [];
        $nativeVars = [];

        foreach ($mergedVariables as $vSpec) {
            $normName = (string) $vSpec['name'];
            $bricksName = ltrim($normName, '-');
            $value = (string) $vSpec['value'];
            $layer = (string) $vSpec['layer'];
            $role = (string) ($vSpec['role'] ?? '');

            $metaVars[$normName] = [
                'layer' => $layer,
                'role' => substr(trim(sanitize_text_field($role)), 0, 200),
            ];

            $rawEntry = isset($vSpec['_raw']) && is_array($vSpec['_raw']) ? $vSpec['_raw'] : null;
            if ($rawEntry !== null && (string) ($rawEntry['value'] ?? '') === $value) {
                // Keep untouched variable byte-for-byte if it already matches
                $nativeVars[] = $rawEntry;
                continue;
            }

            $catId = $ensureCategory($layer);
            if ($rawEntry !== null) {
                $entry = $rawEntry;
                $entry['name'] = $bricksName;
                $entry['value'] = $value;
                if (!isset($entry['category']) || $entry['category'] === '') {
                    $entry['category'] = $catId;
                }
                $nativeVars[] = $entry;
                continue;
            }

            $id = isset($vSpec['id']) && is_string($vSpec['id']) && $vSpec['id'] !== ''
                ? $vSpec['id']
                : $this->generateUniqueDesignId($usedIds);

            $nativeVars[] = [
                'id' => $id,
                'name' => $bricksName,
                'value' => $value,
                'category' => $catId,
            ];
        }

        if ($catsModified) {
            update_option(self::OPT_GLOBAL_VARIABLES_CATEGORIES, $rawCats);
        }
        update_option(self::OPT_GLOBAL_VARIABLES, $nativeVars);

        $metaAll['variables'] = $metaVars;
        $metaRepo->replaceAll($metaAll);
    }

    /**
     * Persist validated merged classes (moving deleted classes to trash and preserving hand-authored `_cssCustom`).
     *
     * @param array<string, array<string, mixed>> $mergedClasses
     * @param array<string, array<string, mixed>> $deletedClasses
     */
    public function saveMergedClasses(array $mergedClasses, array $deletedClasses, MetaRepository $metaRepo): void
    {
        $trash = get_option('bricks_global_classes_trash', []);
        if (!is_array($trash)) {
            $trash = [];
        }

        $usedIds = [];
        foreach ($trash as $tItem) {
            if (is_array($tItem) && isset($tItem['id']) && is_string($tItem['id'])) {
                $usedIds[$tItem['id']] = true;
            }
        }
        foreach ($mergedClasses as $cSpec) {
            if (isset($cSpec['id']) && is_string($cSpec['id'])) {
                $usedIds[$cSpec['id']] = true;
            }
        }

        // Move deleted classes to bricks_global_classes_trash and clean up MetaRepository
        if ($deletedClasses !== []) {
            foreach ($deletedClasses as $delName => $delSpec) {
                $delRaw = isset($delSpec['_raw']) && is_array($delSpec['_raw'])
                    ? $delSpec['_raw']
                    : ['id' => $delSpec['id'] ?? $this->generateUniqueDesignId($usedIds), 'name' => $delName, 'settings' => []];
                $trash[] = $delRaw;
                $metaRepo->removeClassMeta((string) $delName, isset($delSpec['id']) ? (string) $delSpec['id'] : null);
            }
            update_option('bricks_global_classes_trash', array_values($trash));
        }

        $nativeClasses = [];
        foreach ($mergedClasses as $cSpec) {
            $name = (string) $cSpec['name'];
            $kind = (string) $cSpec['kind'];
            $purpose = (string) ($cSpec['purpose'] ?? '');
            /** @var array<string, string> $declarations */
            $declarations = is_array($cSpec['declarations'] ?? null) ? $cSpec['declarations'] : [];

            $rawEntry = isset($cSpec['_raw']) && is_array($cSpec['_raw']) ? $cSpec['_raw'] : null;
            $id = $rawEntry !== null && isset($rawEntry['id']) && is_string($rawEntry['id'])
                ? $rawEntry['id']
                : (isset($cSpec['id']) && is_string($cSpec['id']) && $cSpec['id'] !== '' ? $cSpec['id'] : $this->generateUniqueDesignId($usedIds));

            $metaRepo->setClassMeta($name, $kind, $purpose, $id);

            if ($rawEntry !== null) {
                $oldSettings = isset($rawEntry['settings']) && is_array($rawEntry['settings']) ? $rawEntry['settings'] : [];
                $oldCssCustom = isset($oldSettings['_cssCustom']) && is_string($oldSettings['_cssCustom']) ? $oldSettings['_cssCustom'] : '';
                $oldParsed = $this->parseClassCssCustom($oldCssCustom, $name);

                // If declarations did not change at all, preserve the existing native class entry byte-for-byte!
                if ($oldParsed['declarations'] === $declarations) {
                    $nativeClasses[] = $rawEntry;
                    continue;
                }

                $entry = $rawEntry;
                $settings = $oldSettings;
                $newCssCustom = $this->buildClassCssCustom($name, $declarations, $oldParsed['custom_css']);
                if ($newCssCustom !== '') {
                    $settings['_cssCustom'] = $newCssCustom;
                } else {
                    unset($settings['_cssCustom']);
                }
                $entry['settings'] = $settings;
                $entry['modified'] = time();
                $entry['user_id'] = get_current_user_id() ?: 1;
                $nativeClasses[] = $entry;
                continue;
            }

            $settings = [];
            $newCssCustom = $this->buildClassCssCustom($name, $declarations, '');
            if ($newCssCustom !== '') {
                $settings['_cssCustom'] = $newCssCustom;
            }

            $nativeClasses[] = [
                'id' => $id,
                'name' => $name,
                'settings' => $settings,
                'modified' => time(),
                'user_id' => get_current_user_id() ?: 1,
            ];
        }

        $this->putClasses($nativeClasses);
    }

    /**
     * Persist a deep-merged Theme Style update (never modifying `conditions`).
     *
     * @param array<string, mixed> $patchSettings
     */
    public function saveMergedThemeStyle(string $styleName, ?string $label, array $patchSettings): void
    {
        $all = $this->baseStyles();

        $targetKey = $styleName;
        if (!isset($all[$targetKey])) {
            foreach ($all as $k => $v) {
                if (is_array($v) && isset($v['label']) && strtolower((string) $v['label']) === strtolower($styleName)) {
                    $targetKey = (string) $k;
                    break;
                }
            }
        }

        $existing = isset($all[$targetKey]) && is_array($all[$targetKey])
            ? $all[$targetKey]
            : ['label' => $label ?? $styleName, 'settings' => ['_custom' => true]];

        if ($label !== null && $label !== '') {
            $existing['label'] = $label;
        }

        $existingSettings = isset($existing['settings']) && is_array($existing['settings']) ? $existing['settings'] : [];
        $preservedConditions = $existingSettings['conditions'] ?? null;

        unset($patchSettings['conditions']);
        $mergedSettings = $this->deepMergeSettings($existingSettings, $patchSettings);
        if ($preservedConditions !== null) {
            $mergedSettings['conditions'] = $preservedConditions;
        }

        $existing['settings'] = $mergedSettings;
        $all[$targetKey] = $existing;
        update_option(self::OPT_THEME_STYLES, $all);
    }

    public function afterDesignWrite(string $area = 'preset'): void
    {
        // Refresh Bricks in-memory global data if Bricks is loaded
        if (class_exists('\Bricks\Database')) {
            if (isset(\Bricks\Database::$global_data) && is_array(\Bricks\Database::$global_data)) {
                \Bricks\Database::$global_data['colorPalette'] = get_option(self::OPT_COLOR_PALETTE, []);
                \Bricks\Database::$global_data['globalVariables'] = get_option(self::OPT_GLOBAL_VARIABLES, []);
                \Bricks\Database::$global_data['globalVariablesCategories'] = get_option(self::OPT_GLOBAL_VARIABLES_CATEGORIES, []);
                \Bricks\Database::$global_data['globalClasses'] = get_option(self::OPT_GLOBAL_CLASSES, []);
                \Bricks\Database::$global_data['themeStyles'] = get_option(self::OPT_THEME_STYLES, []);
            }
        }

        if (
            class_exists('\Bricks\Database')
            && method_exists('\Bricks\Database', 'get_setting')
            && \Bricks\Database::get_setting('cssLoading') === 'file'
        ) {
            if (in_array($area, ['colors', 'preset'], true) && class_exists('\Bricks\Assets_Color_Palettes') && method_exists('\Bricks\Assets_Color_Palettes', 'generate_css_file')) {
                \Bricks\Assets_Color_Palettes::generate_css_file(get_option(self::OPT_COLOR_PALETTE, []));
            }
            if (in_array($area, ['variables', 'preset'], true) && class_exists('\Bricks\Assets_Global_Variables') && method_exists('\Bricks\Assets_Global_Variables', 'generate_css_file')) {
                \Bricks\Assets_Global_Variables::generate_css_file(get_option(self::OPT_GLOBAL_VARIABLES, []));
            }
            if (in_array($area, ['theme-styles', 'preset'], true) && class_exists('\Bricks\Assets_Theme_Styles') && method_exists('\Bricks\Assets_Theme_Styles', 'generate_css_file')) {
                \Bricks\Assets_Theme_Styles::generate_css_file(get_option(self::OPT_THEME_STYLES, []));
            }
            if (class_exists('\Bricks\Assets_Files') && method_exists('\Bricks\Assets_Files', 'schedule_css_file_regeneration')) {
                \Bricks\Assets_Files::schedule_css_file_regeneration();
            }
        }

        DesignContextBuilder::invalidateCache();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function elementRegistry(): array
    {
        if (class_exists('\Bricks\Elements')) {
            if (empty(\Bricks\Elements::$elements) && method_exists('\Bricks\Elements', 'init_elements')) {
                \Bricks\Elements::init_elements();
            }
        }

        $bricksElements = class_exists('\Bricks\Elements') && is_array(\Bricks\Elements::$elements ?? null)
            ? \Bricks\Elements::$elements
            : [];

        $schema = $this->getSchema();
        $leanTypes = is_array($schema['elements'] ?? null) ? $schema['elements'] : [];
        $registry = [];

        foreach ($leanTypes as $leanType => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $nativeName = (string) ($spec['native'] ?? $leanType);
            // If Bricks element registry is loaded and this native element was disabled in Bricks Element Manager, skip it
            if (!empty($bricksElements) && !isset($bricksElements[$nativeName])) {
                continue;
            }

            $bricksMeta = [];
            if (class_exists('\Bricks\Elements') && method_exists('\Bricks\Elements', 'get_element')) {
                $loaded = \Bricks\Elements::get_element(['name' => $nativeName]);
                if (is_array($loaded)) {
                    $bricksMeta = $loaded;
                }
            }

            $isContainer = (bool) ($spec['container'] ?? false);
            $baseKeys = ['type', 'label', 'classes', 'extra'];
            if ($leanType === 'section') {
                array_unshift($baseKeys, 'ref');
            }
            if ($isContainer) {
                $baseKeys[] = 'children';
            }
            $typeKeys = array_values(array_unique(array_merge($baseKeys, is_array($spec['keys'] ?? null) ? $spec['keys'] : [])));

            $registry[(string) $leanType] = [
                'type' => (string) $leanType,
                'native_name' => $nativeName,
                'label' => isset($bricksMeta['label']) && is_string($bricksMeta['label'])
                    ? $bricksMeta['label']
                    : ucfirst((string) $leanType),
                'category' => isset($bricksMeta['category']) && is_string($bricksMeta['category'])
                    ? $bricksMeta['category']
                    : ($isContainer ? 'layout' : 'basic'),
                'nestable' => $isContainer,
                'allowed_keys' => $typeKeys,
            ];
        }

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->loadSchema();
    }

    public function buildSiteVocabulary(?MetaRepository $metaRepo = null): SiteVocabulary
    {
        if (class_exists('\Bricks\Elements')) {
            if (empty(\Bricks\Elements::$elements) && method_exists('\Bricks\Elements', 'init_elements')) {
                \Bricks\Elements::init_elements();
            }
        }

        $registeredNative = [];
        if (class_exists('\Bricks\Elements') && is_array(\Bricks\Elements::$elements ?? null)) {
            foreach (\Bricks\Elements::$elements as $nativeName => $elData) {
                $registeredNative[(string) $nativeName] = is_array($elData) ? $elData : ['name' => (string) $nativeName];
            }
        }

        $discipline = (string) get_option('ln_builder_token_discipline', 'strict');
        $severity = $discipline === 'warn' ? Problem::SEVERITY_WARNING : Problem::SEVERITY_ERROR;

        return new SiteVocabulary(
            builder: 'bricks',
            siteId: (string) get_option(Installer::OPTION_SITE_ID, ''),
            extraSecret: Installer::extraSecret(),
            classes: $this->classes(),
            variables: $this->variables(),
            colors: $this->colors(),
            allowedAreas: $this->capabilities()->areas(),
            registeredElements: $registeredNative,
            extraEnabled: true,
            tokenDiscipline: $severity,
        );
    }

    public function afterWrite(int $postId): void
    {
        global $wpdb;

        $nowLocal = current_time('mysql');
        $nowGmt = current_time('mysql', true);
        $wpdb->update(
            $wpdb->posts,
            [
                'post_modified' => $nowLocal,
                'post_modified_gmt' => $nowGmt,
            ],
            ['ID' => $postId],
            ['%s', '%s'],
            ['%d']
        );

        if (
            class_exists('\Bricks\Assets_Files')
            && class_exists('\Bricks\Database')
            && method_exists('\Bricks\Database', 'get_setting')
            && \Bricks\Database::get_setting('cssLoading') === 'file'
        ) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                if ($post->post_type === self::POST_TYPE_TEMPLATE) {
                    $tType = $this->templateType($postId) ?? 'content';
                    $area = $this->areaForTemplateType($tType);
                    $elements = $this->contentArea($postId, $area);
                    if (method_exists('\Bricks\Assets_Files', 'generate_post_css_file')) {
                        \Bricks\Assets_Files::generate_post_css_file($postId, $area, $elements);
                    }
                } else {
                    $elements = $this->contentArea($postId, 'content');
                    if (method_exists('\Bricks\Assets_Files', 'generate_post_css_file')) {
                        \Bricks\Assets_Files::generate_post_css_file($postId, 'content', $elements);
                    }
                }
            }
        }

        clean_post_cache($postId);
    }

    public function tryRender(int $postId, string $area): bool
    {
        if (!class_exists('\Bricks\Frontend') || !method_exists('\Bricks\Frontend', 'render_data')) {
            return true;
        }

        $elements = $this->contentArea($postId, $area);
        if ($elements === []) {
            return true;
        }

        $initialObLevel = ob_get_level();
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (in_array($errno, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
            return false;
        });

        try {
            ob_start();
            \Bricks\Frontend::render_data($elements, $area, $postId);
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            while (ob_get_level() > $initialObLevel) {
                @ob_end_clean();
            }
            restore_error_handler();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTemplateSummary(\WP_Post $post): array
    {
        $modifiedGmt = $post->post_modified_gmt !== '0000-00-00 00:00:00' && $post->post_modified_gmt !== ''
            ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($post->post_modified_gmt . ' UTC'))
            : gmdate('Y-m-d\TH:i:s\Z');

        $isComponent = (bool) get_post_meta((int) $post->ID, '_ln_builder_component', true);

        return [
            'id' => (int) $post->ID,
            'slug' => (string) $post->post_name,
            'title' => (string) $post->post_title,
            'type' => $this->templateType((int) $post->ID) ?? 'content',
            'status' => (string) $post->post_status,
            'component' => $isComponent,
            'modified' => $modifiedGmt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPageSummary(\WP_Post $post): array
    {
        $modifiedGmt = $post->post_modified_gmt !== '0000-00-00 00:00:00' && $post->post_modified_gmt !== ''
            ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($post->post_modified_gmt . ' UTC'))
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'id' => (int) $post->ID,
            'slug' => (string) $post->post_name,
            'title' => (string) $post->post_title,
            'parent' => (int) $post->post_parent,
            'status' => (string) $post->post_status,
            'editor_mode' => $this->detectEditorMode($post),
            'modified' => $modifiedGmt,
        ];
    }

    private function detectEditorMode(\WP_Post $post): string
    {
        $mode = get_post_meta((int) $post->ID, self::META_EDITOR_MODE, true);
        if ($mode === 'bricks') {
            return 'bricks';
        }

        $bricksContent = get_post_meta((int) $post->ID, self::META_CONTENT, true);
        if (is_array($bricksContent) && $bricksContent !== []) {
            return 'bricks';
        }

        if (function_exists('has_blocks') && has_blocks($post->post_content)) {
            return 'block';
        }

        return 'classic';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(): array
    {
        if ($this->schemaCache === null) {
            /** @var array<string, mixed> $schema */
            $schema = require LN_BUILDER_PLUGIN_DIR . 'src/Converter/Schema/bricks.php';
            $this->schemaCache = $schema;
        }
        return $this->schemaCache;
    }

    /**
     * Parse `_cssCustom` from a Bricks global class into `declarations` (managed by the bridge)
     * and `custom_css` (hand-authored rules outside the bridge markers).
     *
     * @return array{declarations: array<string, string>, custom_css: string}
     */
    private function parseClassCssCustom(string $rawCss, string $className): array
    {
        $rawCss = trim($rawCss);
        if ($rawCss === '') {
            return ['declarations' => [], 'custom_css' => ''];
        }

        $startMarker = '/* ln-builder:start */';
        $endMarker = '/* ln-builder:end */';
        $startPos = strpos($rawCss, $startMarker);
        $endPos = strpos($rawCss, $endMarker);

        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
            $managedBlock = substr(
                $rawCss,
                $startPos + strlen($startMarker),
                $endPos - ($startPos + strlen($startMarker))
            );
            $before = trim(substr($rawCss, 0, $startPos));
            $after = trim(substr($rawCss, $endPos + strlen($endMarker)));
            $remainder = trim($before . ($before !== '' && $after !== '' ? "\n\n" : '') . $after);

            return [
                'declarations' => $this->extractSimpleDeclarations($managedBlock),
                'custom_css' => $remainder,
            ];
        }

        // If there are no markers, check if rawCss is a single simple rule `.{$className} { ... }` or `%root% { ... }`
        $escapedName = preg_quote($className, '/');
        if (
            !str_contains($rawCss, '@')
            && substr_count($rawCss, '{') === 1
            && substr_count($rawCss, '}') === 1
            && preg_match('/^(?:\.' . $escapedName . '|%root%)\s*\{([^{}]*)\}\s*$/s', $rawCss, $m)
        ) {
            return [
                'declarations' => $this->extractSimpleDeclarations($m[0]),
                'custom_css' => '',
            ];
        }

        // Complex hand-authored CSS without markers: preserve all of it in `custom_css`
        return [
            'declarations' => [],
            'custom_css' => $rawCss,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractSimpleDeclarations(string $ruleBlock): array
    {
        $declarations = [];
        if (preg_match('/\{([^{}]*)\}/s', $ruleBlock, $m)) {
            $body = $m[1];
        } else {
            $body = $ruleBlock;
        }

        $parts = explode(';', $body);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, ':')) {
                continue;
            }
            [$prop, $val] = explode(':', $part, 2);
            $prop = trim($prop);
            $val = trim($val);
            if ($prop !== '' && $val !== '') {
                $declarations[$prop] = $val;
            }
        }

        return $declarations;
    }

    /**
     * Format bridge-managed declarations inside `/* ln-builder:start * / ... /* ln-builder:end * /`
     * and append any preserved hand-authored CSS outside the markers.
     *
     * @param array<string, string> $declarations
     */
    private function buildClassCssCustom(string $className, array $declarations, string $remainderCustomCss): string
    {
        $managed = '';
        if ($declarations !== []) {
            $lines = [];
            foreach ($declarations as $prop => $val) {
                $lines[] = "  {$prop}: {$val};";
            }
            $managed = "/* ln-builder:start */\n.{$className} {\n" . implode("\n", $lines) . "\n}\n/* ln-builder:end */";
        }

        $remainderCustomCss = trim($remainderCustomCss);
        if ($managed !== '' && $remainderCustomCss !== '') {
            return $managed . "\n\n" . $remainderCustomCss;
        }

        return $managed !== '' ? $managed : $remainderCustomCss;
    }

    /**
     * @param array<string, bool> $usedIds
     */
    private function generateUniqueDesignId(array &$usedIds): string
    {
        for ($i = 0; $i < 100; $i++) {
            $candidate = Expander::defaultIdGenerator();
            if (!isset($usedIds[$candidate])) {
                $usedIds[$candidate] = true;
                return $candidate;
            }
        }
        $fallback = substr(bin2hex(random_bytes(4)), 0, 6);
        $usedIds[$fallback] = true;
        return $fallback;
    }

    /**
     * Deep-merge patch settings into existing settings (removing keys set to `null`).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function deepMergeSettings(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
                continue;
            }
            if (is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                $base[$key] = $this->deepMergeSettings($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
