<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Builders\Elementor;

use LiveNetworks\BuilderConnector\Builders\BuilderAdapter;
use LiveNetworks\BuilderConnector\Builders\Capabilities;
use LiveNetworks\BuilderConnector\Converter\Problem;
use LiveNetworks\BuilderConnector\Converter\Revision;
use LiveNetworks\BuilderConnector\Converter\SiteVocabulary;
use LiveNetworks\BuilderConnector\DesignContext\DesignContextBuilder;
use LiveNetworks\BuilderConnector\Install\Installer;
use LiveNetworks\BuilderConnector\ManagedCss\ManagedCssManager;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;

final class ElementorAdapter implements BuilderAdapter
{
    public const META_DATA = '_elementor_data';
    public const META_EDIT_MODE = '_elementor_edit_mode';
    public const META_TEMPLATE_TYPE = '_elementor_template_type';
    public const META_PAGE_SETTINGS = '_elementor_page_settings';
    public const META_CONDITIONS = '_elementor_conditions';
    public const POST_TYPE_TEMPLATE = 'elementor_library';
    public const TAXONOMY_TEMPLATE_TYPE = 'elementor_library_type';
    public const OPTION_ACTIVE_KIT = 'elementor_active_kit';

    public const ALLOWED_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    private string $version;

    public function __construct(string $version = '')
    {
        $this->version = $version !== ''
            ? $version
            : (defined('ELEMENTOR_PRO_VERSION') ? (string) ELEMENTOR_PRO_VERSION : (defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.20.0'));
    }

    public function name(): string
    {
        return 'elementor';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function capabilities(): Capabilities
    {
        $templateTypes = ['header', 'footer', 'section', 'page', 'popup', 'single', 'archive'];

        return new Capabilities(
            globalClasses: 'managed_css',
            variables: 'managed_css',
            globalColors: 'native',
            globalFonts: 'native',
            areas: ['content', 'header', 'footer', 'popup', 'single', 'archive'],
            templateTypes: $templateTypes,
            displayConditions: 'read'
        );
    }

    public function areaForTemplateType(string $templateType): string
    {
        return match ($templateType) {
            'header' => 'header',
            'footer' => 'footer',
            'popup' => 'popup',
            default => 'content',
        };
    }

    /**
     * Retrieve and flatten Elementor nested elements into standard internal element list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contentArea(int $postId, string $area): array
    {
        $raw = get_post_meta($postId, self::META_DATA, true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $nested = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $nested = $raw;
        } else {
            $nested = [];
        }

        if ($nested === []) {
            return [];
        }

        // If data is already flat (e.g. from unit testing), return as is
        if (isset($nested[0]['name']) && (isset($nested[0]['parent']) || isset($nested[0]['children']))) {
            return $nested;
        }

        return self::flattenNestedElements($nested);
    }

    /**
     * Put content area: converts internal elements into Elementor nested tree and updates _elementor_data.
     *
     * @param array<int, array<string, mixed>> $native
     */
    public function putContentArea(int $postId, string $area, array $native): void
    {
        // If native elements are flat, nest them for Elementor
        $nested = self::isNestedElementorData($native) ? $native : self::nestFlatElements($native);

        $json = wp_json_encode($nested, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '[]';
        }

        update_post_meta($postId, self::META_DATA, wp_slash($json));
        update_post_meta($postId, self::META_EDIT_MODE, 'builder');
        update_post_meta($postId, '_elementor_version', defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.20.0');
        if (defined('ELEMENTOR_PRO_VERSION')) {
            update_post_meta($postId, '_elementor_pro_version', (string) ELEMENTOR_PRO_VERSION);
        }
    }

    /**
     * List templates.
     *
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function templates(array $filter): array
    {
        return $this->templatesWithCount($filter)['items'];
    }

    /**
     * List templates with pagination count.
     *
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function templatesWithCount(array $filter): array
    {
        $metaRepo = new MetaRepository();
        $queryArgs = [
            'post_type' => self::POST_TYPE_TEMPLATE,
            'posts_per_page' => isset($filter['per_page']) ? (int) $filter['per_page'] : 50,
            'paged' => isset($filter['page']) ? (int) $filter['page'] : 1,
            'post_status' => self::ALLOWED_POST_STATUSES,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (!empty($filter['search']) && is_string($filter['search'])) {
            $queryArgs['s'] = $filter['search'];
        }

        $taxQuery = [];
        if (!empty($filter['type']) && is_string($filter['type'])) {
            $taxQuery[] = [
                'taxonomy' => self::TAXONOMY_TEMPLATE_TYPE,
                'field' => 'slug',
                'terms' => [$filter['type']],
            ];
        }
        if ($taxQuery !== []) {
            $queryArgs['tax_query'] = $taxQuery;
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }
            $isComponent = (bool) get_post_meta((int) $post->ID, '_ln_builder_component', true);
            if (isset($filter['component']) && $filter['component'] !== '') {
                $compFilter = filter_var($filter['component'], FILTER_VALIDATE_BOOLEAN);
                if ($isComponent !== $compFilter) {
                    continue;
                }
            }

            $tplType = $this->templateType($post->ID) ?? 'section';
            $items[] = [
                'id' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'type' => $tplType,
                'status' => $post->post_status,
                'component' => $isComponent,
                'modified' => get_post_modified_time('c', true, $post),
            ];
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page' => (int) $queryArgs['paged'],
            'per_page' => (int) $queryArgs['posts_per_page'],
        ];
    }

    public function templateDetails(int $postId): ?array
    {
        $post = get_post($postId);
        if (!($post instanceof \WP_Post) || $post->post_type !== self::POST_TYPE_TEMPLATE) {
            return null;
        }

        $type = $this->templateType($postId) ?? 'section';

        return [
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'type' => $type,
            'status' => $post->post_status,
            'component' => (bool) get_post_meta($post->ID, '_ln_builder_component', true),
            'modified' => get_post_modified_time('c', true, $post),
        ];
    }

    public function templateType(int $postId): ?string
    {
        $metaType = get_post_meta($postId, self::META_TEMPLATE_TYPE, true);
        if (is_string($metaType) && $metaType !== '') {
            return $metaType;
        }

        $terms = wp_get_post_terms($postId, self::TAXONOMY_TEMPLATE_TYPE);
        if (!is_wp_error($terms) && !empty($terms) && isset($terms[0]->slug)) {
            return (string) $terms[0]->slug;
        }

        return null;
    }

    public function findTemplateId($idOrSlug): ?int
    {
        if (is_numeric($idOrSlug) && (int) $idOrSlug > 0) {
            $post = get_post((int) $idOrSlug);
            if ($post instanceof \WP_Post && $post->post_type === self::POST_TYPE_TEMPLATE && $post->post_status !== 'trash') {
                return $post->ID;
            }
            return null;
        }

        if (is_string($idOrSlug) && $idOrSlug !== '') {
            $posts = get_posts([
                'name' => $idOrSlug,
                'post_type' => self::POST_TYPE_TEMPLATE,
                'post_status' => self::ALLOWED_POST_STATUSES,
                'posts_per_page' => 1,
            ]);
            if ($posts !== [] && $posts[0] instanceof \WP_Post) {
                return $posts[0]->ID;
            }
        }

        return null;
    }

    public function createTemplate(string $title, ?string $slug, string $type): int
    {
        $postData = [
            'post_title' => $title,
            'post_type' => self::POST_TYPE_TEMPLATE,
            'post_status' => 'draft',
        ];
        if ($slug !== null && $slug !== '') {
            $postData['post_name'] = $slug;
        }

        $postId = wp_insert_post($postData, true);
        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create template post: ' . $postId->get_error_message());
        }

        update_post_meta($postId, self::META_TEMPLATE_TYPE, $type);
        update_post_meta($postId, self::META_EDIT_MODE, 'builder');
        wp_set_object_terms($postId, [$type], self::TAXONOMY_TEMPLATE_TYPE);

        return $postId;
    }

    public function pages(array $filter): array
    {
        return $this->pagesWithCount($filter)['items'];
    }

    public function pagesWithCount(array $filter): array
    {
        $queryArgs = [
            'post_type' => 'page',
            'posts_per_page' => isset($filter['per_page']) ? (int) $filter['per_page'] : 50,
            'paged' => isset($filter['page']) ? (int) $filter['page'] : 1,
            'post_status' => self::ALLOWED_POST_STATUSES,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (!empty($filter['search']) && is_string($filter['search'])) {
            $queryArgs['s'] = $filter['search'];
        }
        if (!empty($filter['status']) && is_string($filter['status'])) {
            $queryArgs['post_status'] = $filter['status'];
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        foreach ($query->posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }
            $items[] = [
                'id' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'modified' => get_post_modified_time('c', true, $post),
            ];
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page' => (int) $queryArgs['paged'],
            'per_page' => (int) $queryArgs['posts_per_page'],
        ];
    }

    public function pageDetails(int $postId): ?array
    {
        $post = get_post($postId);
        if (!($post instanceof \WP_Post) || $post->post_type !== 'page') {
            return null;
        }

        return [
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'modified' => get_post_modified_time('c', true, $post),
        ];
    }

    public function createPage(string $title, ?string $slug, ?int $parent): int
    {
        $postData = [
            'post_title' => $title,
            'post_type' => 'page',
            'post_status' => 'draft',
        ];
        if ($slug !== null && $slug !== '') {
            $postData['post_name'] = $slug;
        }
        if ($parent !== null && $parent > 0) {
            $postData['post_parent'] = $parent;
        }

        $postId = wp_insert_post($postData, true);
        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create page post: ' . $postId->get_error_message());
        }

        update_post_meta($postId, self::META_EDIT_MODE, 'builder');
        return $postId;
    }

    public function publish(int $postId): void
    {
        wp_update_post([
            'ID' => $postId,
            'post_status' => 'publish',
        ]);
    }

    public function editLink(int $postId): string
    {
        return admin_url('post.php?post=' . $postId . '&action=elementor');
    }

    /**
     * Global classes from Managed CSS block.
     *
     * @return array<int, array<string, mixed>>
     */
    public function classes(): array
    {
        return ManagedCssManager::get()['classes'];
    }

    public function putClasses(array $classes): void
    {
        ManagedCssManager::set(['classes' => $classes]);
        $this->syncManagedCssToKit();
    }

    /**
     * Variables from Managed CSS block.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variables(): array
    {
        return ManagedCssManager::get()['variables'];
    }

    public function putVariables(array $variables): void
    {
        ManagedCssManager::set(['variables' => $variables]);
        $this->syncManagedCssToKit();
    }

    /**
     * Global colors from active Elementor kit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function colors(): array
    {
        $kitSettings = $this->getKitSettings();
        $out = [];

        // System colors (Primary, Secondary, Text, Accent)
        $system = is_array($kitSettings['system_colors'] ?? null) ? $kitSettings['system_colors'] : [];
        foreach ($system as $item) {
            if (!is_array($item) || empty($item['_id']) || empty($item['color'])) {
                continue;
            }
            $id = (string) $item['_id'];
            $name = (string) ($item['title'] ?? $id);
            $out[] = [
                'id' => $id,
                'name' => sanitize_title($name),
                'value' => (string) $item['color'],
                'css_var' => '--e-global-color-' . $id,
                'source' => 'builder',
            ];
        }

        // Custom colors
        $custom = is_array($kitSettings['custom_colors'] ?? null) ? $kitSettings['custom_colors'] : [];
        foreach ($custom as $item) {
            if (!is_array($item) || empty($item['_id']) || empty($item['color'])) {
                continue;
            }
            $id = (string) $item['_id'];
            $name = (string) ($item['title'] ?? $id);
            $out[] = [
                'id' => $id,
                'name' => sanitize_title($name),
                'value' => (string) $item['color'],
                'css_var' => '--e-global-color-' . $id,
                'source' => 'builder',
            ];
        }

        return $out;
    }

    public function putColors(array $colors): void
    {
        $kitId = $this->getActiveKitId();
        if ($kitId <= 0) {
            return;
        }

        $settings = $this->getKitSettings();
        $customColors = [];

        foreach ($colors as $c) {
            $id = (string) ($c['id'] ?? substr(hash('sha256', (string) $c['name']), 0, 8));
            $customColors[] = [
                '_id' => $id,
                'title' => (string) ($c['name'] ?? $id),
                'color' => (string) ($c['value'] ?? '#000000'),
            ];
        }

        $settings['custom_colors'] = $customColors;
        update_post_meta($kitId, self::META_PAGE_SETTINGS, $settings);
    }

    public function baseStyles(): array
    {
        $kitSettings = $this->getKitSettings();
        return is_array($kitSettings['site_settings'] ?? null) ? $kitSettings['site_settings'] : [];
    }

    public function putBaseStyles(string $name, array $styles): void
    {
        $kitId = $this->getActiveKitId();
        if ($kitId <= 0) {
            return;
        }

        $settings = $this->getKitSettings();
        $current = is_array($settings['site_settings'] ?? null) ? $settings['site_settings'] : [];
        $settings['site_settings'] = array_merge($current, $styles);
        update_post_meta($kitId, self::META_PAGE_SETTINGS, $settings);
    }

    public function designSystem(MetaRepository $metaRepo): array
    {
        $colors = $this->colors();
        $variables = $this->variables();
        $classes = $this->classes();
        $themeStyles = $this->baseStyles();

        $revColors = Revision::compute($colors);
        $revVariables = Revision::compute($variables);
        $revClasses = Revision::compute($classes);
        $revTheme = Revision::compute($themeStyles);

        $topRevision = Revision::compute([
            'colors' => $revColors,
            'variables' => $revVariables,
            'classes' => $revClasses,
            'theme' => $revTheme,
        ]);

        return [
            'builder' => 'elementor',
            'revision' => $topRevision,
            'revisions' => [
                'colors' => $revColors,
                'variables' => $revVariables,
                'classes' => $revClasses,
                'theme_styles' => $revTheme,
            ],
            'colors' => $colors,
            'variables' => $variables,
            'classes' => $classes,
            'theme_styles' => $themeStyles,
        ];
    }

    public function designValidationState(MetaRepository $metaRepo): array
    {
        return [
            'colors' => $this->colors(),
            'variables' => $this->variables(),
            'classes' => $this->classes(),
            'theme_tokens' => [],
        ];
    }

    public function findDesignUsagesInContent(string $type, string $identifier): array
    {
        global $wpdb;
        $needle = $type === 'class' ? $identifier : $identifier;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 100",
                self::META_DATA,
                '%' . $wpdb->esc_like($needle) . '%'
            ),
            ARRAY_A
        );

        $usages = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid = (int) $row['post_id'];
                $postType = get_post_type($pid);
                $usages[] = ($postType === self::POST_TYPE_TEMPLATE ? 'template:' : 'page:') . $pid;
            }
        }

        return array_values(array_unique($usages));
    }

    public function rawDesignSnapshot(string $area = 'preset'): array
    {
        return [
            'builder' => 'elementor',
            'area' => $area,
            'managed_css' => ManagedCssManager::get(),
            'kit_settings' => $this->getKitSettings(),
        ];
    }

    public function restoreRawDesignSnapshot(array $snapshot): void
    {
        if (isset($snapshot['managed_css']) && is_array($snapshot['managed_css'])) {
            ManagedCssManager::set($snapshot['managed_css']);
            $this->syncManagedCssToKit();
        }

        if (isset($snapshot['kit_settings']) && is_array($snapshot['kit_settings'])) {
            $kitId = $this->getActiveKitId();
            if ($kitId > 0) {
                update_post_meta($kitId, self::META_PAGE_SETTINGS, $snapshot['kit_settings']);
            }
        }
    }

    public function saveMergedColors(array $mergedColors, MetaRepository $metaRepo): void
    {
        $this->putColors(array_values($mergedColors));
    }

    public function saveMergedVariables(array $mergedVariables, MetaRepository $metaRepo): void
    {
        $this->putVariables(array_values($mergedVariables));
    }

    public function saveMergedClasses(array $mergedClasses, array $deletedClasses, MetaRepository $metaRepo): void
    {
        $this->putClasses(array_values($mergedClasses));
    }

    public function saveMergedThemeStyle(string $styleName, ?string $label, array $patchSettings): void
    {
        $this->putBaseStyles($styleName, $patchSettings);
    }

    public function afterDesignWrite(string $area = 'preset'): void
    {
        DesignContextBuilder::invalidate();
        $this->clearElementorCache();
    }

    public function afterWrite(int $postId): void
    {
        $this->clearElementorCache($postId);
    }

    public function tryRender(int $postId, string $area): bool
    {
        // Safe verification: ensure _elementor_data can be read and decoded
        $data = get_post_meta($postId, self::META_DATA, true);
        if (!is_string($data) || $data === '') {
            return false;
        }

        $decoded = json_decode($data, true);
        return is_array($decoded);
    }

    public function getSchema(): array
    {
        return require dirname(__DIR__, 2) . '/Converter/Schema/elementor.php';
    }

    public function buildSiteVocabulary(?MetaRepository $metaRepo = null): SiteVocabulary
    {
        // Elementor references classes by name (`_css_classes`), so the class "ID" is the name itself.
        $classes = [];
        foreach ($this->classes() as $cls) {
            $name = is_array($cls) ? (string) ($cls['name'] ?? '') : '';
            if ($name !== '') {
                $classes[] = ['id' => $name, 'name' => $name];
            }
        }

        $discipline = (string) get_option('ln_builder_token_discipline', 'strict');
        $severity = $discipline === 'warn' ? Problem::SEVERITY_WARNING : Problem::SEVERITY_ERROR;

        return new SiteVocabulary(
            builder: 'elementor',
            siteId: (string) get_option(Installer::OPTION_SITE_ID, ''),
            extraSecret: Installer::extraSecret(),
            classes: $classes,
            variables: $this->variables(),
            colors: $this->colors(),
            allowedAreas: $this->capabilities()->areas(),
            // Empty = every native name is accepted; unmapped widgets become signed passthrough nodes.
            registeredElements: [],
            extraEnabled: true,
            tokenDiscipline: $severity,
        );
    }

    public function elementRegistry(): array
    {
        $schema = $this->getSchema();
        return is_array($schema['elements'] ?? null) ? $schema['elements'] : [];
    }

    /**
     * Get active Elementor kit post ID.
     */
    public function getActiveKitId(): int
    {
        $kitId = (int) get_option(self::OPTION_ACTIVE_KIT, 0);
        if ($kitId > 0 && get_post_status($kitId) !== false) {
            return $kitId;
        }

        // Search for existing kit post
        $kits = get_posts([
            'post_type' => self::POST_TYPE_TEMPLATE,
            'meta_key' => self::META_TEMPLATE_TYPE,
            'meta_value' => 'kit',
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if ($kits !== [] && $kits[0] instanceof \WP_Post) {
            update_option(self::OPTION_ACTIVE_KIT, $kits[0]->ID, true);
            return $kits[0]->ID;
        }

        // Create default kit if missing
        $createdId = wp_insert_post([
            'post_title' => 'Default Kit',
            'post_type' => self::POST_TYPE_TEMPLATE,
            'post_status' => 'publish',
        ]);

        if (is_numeric($createdId) && (int) $createdId > 0) {
            $kid = (int) $createdId;
            update_post_meta($kid, self::META_TEMPLATE_TYPE, 'kit');
            update_option(self::OPTION_ACTIVE_KIT, $kid, true);
            return $kid;
        }

        return 0;
    }

    /**
     * Get active kit page settings array.
     *
     * @return array<string, mixed>
     */
    public function getKitSettings(): array
    {
        $kitId = $this->getActiveKitId();
        if ($kitId <= 0) {
            return [];
        }

        $raw = get_post_meta($kitId, self::META_PAGE_SETTINGS, true);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Synchronize the authoritative Managed CSS block into the Active Kit's Custom CSS.
     */
    public function syncManagedCssToKit(): void
    {
        $kitId = $this->getActiveKitId();
        if ($kitId <= 0) {
            return;
        }

        $settings = $this->getKitSettings();
        $currentCustomCss = (string) ($settings['custom_css'] ?? '');

        $state = ManagedCssManager::get();
        $updatedCustomCss = ManagedCssManager::injectIntoCustomCss(
            $currentCustomCss,
            $state['variables'],
            $state['classes']
        );

        $settings['custom_css'] = $updatedCustomCss;
        update_post_meta($kitId, self::META_PAGE_SETTINGS, $settings);
    }

    /**
     * Clear Elementor CSS cache files and regenerate.
     */
    private function clearElementorCache(?int $postId = null): void
    {
        if (class_exists('\Elementor\Plugin')) {
            if (isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        }
    }

    /**
     * Check whether raw data is in Elementor's nested container format.
     */
    private static function isNestedElementorData(array $elements): bool
    {
        if ($elements === []) {
            return true;
        }
        $first = $elements[0];
        return isset($first['elType']) && array_key_exists('elements', $first);
    }

    /**
     * Convert flat element list (from Expander) into Elementor nested container/widget structure.
     *
     * @param array<int, array<string, mixed>> $flatElements
     * @return array<int, array<string, mixed>>
     */
    public static function nestFlatElements(array $flatElements): array
    {
        if ($flatElements === []) {
            return [];
        }

        $childrenMap = [];
        $itemsById = [];

        foreach ($flatElements as $elem) {
            $id = (string) ($elem['id'] ?? substr(bin2hex(random_bytes(4)), 0, 7));
            $parentId = (string) ($elem['parent'] ?? 0);
            $itemsById[$id] = $elem;
            $childrenMap[$parentId][] = $id;
        }

        $buildSubtree = static function (string $id) use (&$buildSubtree, &$itemsById, &$childrenMap): array {
            $item = $itemsById[$id] ?? [];
            $name = (string) ($item['name'] ?? 'container');
            $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];

            // Lean container types map to Elementor flexbox containers (Expander always emits `container`).
            // `section` / `column` only arrive from flattenNestedElements() (legacy layouts kept via passthrough).
            $elType = match ($name) {
                'container', 'block' => 'container',
                'section', 'column' => $name,
                default => 'widget',
            };

            if (isset($item['label']) && is_string($item['label']) && $item['label'] !== '' && !isset($settings['_title'])) {
                $settings['_title'] = $item['label'];
            }

            $node = [
                'id' => $id,
                'elType' => $elType,
                'settings' => $settings,
                'elements' => [],
            ];

            // Restore other native top-level keys (isInner, isLocked, ...) preserved by flattenNestedElements()
            foreach ($item as $k => $v) {
                if (!in_array($k, ['id', 'name', 'parent', 'children', 'settings', 'label', 'elType', 'widgetType', 'elements'], true)) {
                    $node[$k] = $v;
                }
            }

            if ($elType === 'widget') {
                $node['widgetType'] = $name;
            } elseif ($elType === 'container') {
                // Containers can be moved by patches, so nesting is always derived from the actual parent.
                $node['isInner'] = ((string) ($item['parent'] ?? 0) !== '0');
            }

            $childIds = $childrenMap[$id] ?? [];
            foreach ($childIds as $cId) {
                $node['elements'][] = $buildSubtree((string) $cId);
            }

            return $node;
        };

        $roots = $childrenMap['0'] ?? array_keys($itemsById);
        $tree = [];
        foreach ($roots as $rootId) {
            $tree[] = $buildSubtree((string) $rootId);
        }

        return $tree;
    }

    /**
     * Flatten Elementor nested elements into standard internal flat element list.
     *
     * @param array<int, array<string, mixed>> $nestedElements
     * @return array<int, array<string, mixed>>
     */
    public static function flattenNestedElements(array $nestedElements): array
    {
        $flat = [];

        $traverse = static function (array $node, $parentId) use (&$traverse, &$flat): void {
            $id = (string) ($node['id'] ?? substr(bin2hex(random_bytes(4)), 0, 7));
            $elType = (string) ($node['elType'] ?? 'widget');
            // Widgets are keyed by widgetType; structural nodes keep their elType (container / section / column)
            // so legacy section/column layouts are not silently converted into containers on write-back.
            $name = ($elType === 'widget')
                ? (string) ($node['widgetType'] ?? 'unknown')
                : $elType;

            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];

            $childIds = [];
            foreach ($children as $child) {
                if (is_array($child) && !empty($child['id'])) {
                    $childIds[] = (string) $child['id'];
                }
            }

            $flatNode = [
                'id' => $id,
                'name' => $name,
                'parent' => $parentId,
                'children' => $childIds,
                'settings' => $settings,
            ];
            // Keep remaining native top-level keys (isInner, isLocked, ...) for a lossless round-trip
            foreach ($node as $k => $v) {
                if (!in_array($k, ['id', 'elType', 'widgetType', 'settings', 'elements'], true) && !isset($flatNode[$k])) {
                    $flatNode[$k] = $v;
                }
            }
            $flat[] = $flatNode;

            foreach ($children as $child) {
                if (is_array($child)) {
                    $traverse($child, $id);
                }
            }
        };

        foreach ($nestedElements as $root) {
            if (is_array($root)) {
                $traverse($root, 0);
            }
        }

        return $flat;
    }
}
