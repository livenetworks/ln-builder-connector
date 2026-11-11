<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Builders;

use LiveNetworks\BuilderConnector\Converter\SiteVocabulary;
use LiveNetworks\BuilderConnector\Meta\MetaRepository;

interface BuilderAdapter
{
    public function name(): string;

    public function version(): string;

    public function capabilities(): Capabilities;

    public function areaForTemplateType(string $templateType): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contentArea(int $postId, string $area): array;

    /**
     * @param array<int, array<string, mixed>> $native
     */
    public function putContentArea(int $postId, string $area, array $native): void;

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function templates(array $filter): array;

    /**
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function templatesWithCount(array $filter): array;

    /**
     * @return array<string, mixed>|null
     */
    public function templateDetails(int $postId): ?array;

    public function templateType(int $postId): ?string;

    /**
     * Resolve a non-trashed template post ID by numeric ID or slug.
     *
     * @param int|string $idOrSlug
     */
    public function findTemplateId($idOrSlug): ?int;

    public function createTemplate(string $title, ?string $slug, string $type): int;

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function pages(array $filter): array;

    /**
     * @param array<string, mixed> $filter
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function pagesWithCount(array $filter): array;

    /**
     * @return array<string, mixed>|null
     */
    public function pageDetails(int $postId): ?array;

    public function createPage(string $title, ?string $slug, ?int $parent): int;

    public function publish(int $postId): void;

    public function editLink(int $postId): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function classes(): array;

    /**
     * @param array<int, array<string, mixed>> $classes
     */
    public function putClasses(array $classes): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function variables(): array;

    /**
     * @param array<int, array<string, mixed>> $variables
     */
    public function putVariables(array $variables): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function colors(): array;

    /**
     * @param array<int, array<string, mixed>> $colors
     */
    public function putColors(array $colors): void;

    /**
     * @return array<string, mixed>
     */
    public function baseStyles(): array;

    /**
     * @param array<string, mixed> $styles
     */
    public function putBaseStyles(string $name, array $styles): void;

    /**
     * Build the full normalized lean design-system response for `GET /design-system`.
     *
     * @return array<string, mixed>
     */
    public function designSystem(MetaRepository $metaRepo): array;

    /**
     * Build the internal state map (`colors`, `variables`, `classes`, `theme_tokens`) consumed by `DesignValidator`.
     *
     * @return array<string, mixed>
     */
    public function designValidationState(MetaRepository $metaRepo): array;

    /**
     * Find usages of a class name or CSS variable across pages and templates (used before deleting a token/class).
     *
     * @param 'class'|'variable' $type
     * @return list<string>
     */
    public function findDesignUsagesInContent(string $type, string $identifier): array;

    /**
     * Capture a lossless raw snapshot of the builder's design-system options for the given area (`colors`, `variables`, `classes`, `theme-styles`, `preset`).
     *
     * @return array<string, mixed>
     */
    public function rawDesignSnapshot(string $area = 'preset'): array;

    /**
     * Restore a raw snapshot previously captured by `rawDesignSnapshot()`.
     *
     * @param array<string, mixed> $snapshot
     */
    public function restoreRawDesignSnapshot(array $snapshot): void;

    /**
     * Persist validated merged colors and their `MetaRepository` roles.
     *
     * @param array<string, array<string, mixed>> $mergedColors
     */
    public function saveMergedColors(array $mergedColors, MetaRepository $metaRepo): void;

    /**
     * Persist validated merged variables, their Bricks categories, and their `MetaRepository` layer/role.
     *
     * @param array<string, array<string, mixed>> $mergedVariables
     */
    public function saveMergedVariables(array $mergedVariables, MetaRepository $metaRepo): void;

    /**
     * Persist validated merged classes (moving deleted classes to trash and preserving hand-authored `_cssCustom`).
     *
     * @param array<string, array<string, mixed>> $mergedClasses
     * @param array<string, array<string, mixed>> $deletedClasses
     */
    public function saveMergedClasses(array $mergedClasses, array $deletedClasses, MetaRepository $metaRepo): void;

    /**
     * Persist a deep-merged Theme Style update (never modifying `conditions`).
     *
     * @param array<string, mixed> $patchSettings
     */
    public function saveMergedThemeStyle(string $styleName, ?string $label, array $patchSettings): void;

    public function afterDesignWrite(string $area = 'preset'): void;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function elementRegistry(): array;

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    public function buildSiteVocabulary(?MetaRepository $metaRepo = null): SiteVocabulary;

    public function afterWrite(int $postId): void;

    public function tryRender(int $postId, string $area): bool;
}
