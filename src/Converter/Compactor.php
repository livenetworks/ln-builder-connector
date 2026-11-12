<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Compactor
{
    /**
     * Compact native builder data (Bricks flat element array) into a lean document.
     *
     * @param array<int, array<string, mixed>> $nativeElements
     * @param array<string, mixed> $schema
     */
    public function compact(
        array $nativeElements,
        string $area,
        array $schema,
        SiteVocabulary $vocab
    ): ConversionResult {
        $problems = [];
        $revision = Revision::compute($nativeElements);

        if ($nativeElements === []) {
            return new ConversionResult([
                'area' => $area,
                'revision' => $revision,
                'sections' => [],
            ], $problems);
        }

        // Index elements by ID and partition into root sections (including orphans)
        $partition = self::partitionByRootSections($nativeElements);
        $byId = $partition['byId'];
        $roots = $partition['roots'];

        $nativeToLean = [];
        $elementsMap = is_array($schema['elements'] ?? null) ? $schema['elements'] : [];
        foreach ($elementsMap as $leanType => $spec) {
            if (is_array($spec) && isset($spec['native'])) {
                $nativeToLean[(string) $spec['native']] = [
                    'lean_type' => (string) $leanType,
                    'spec' => $spec,
                ];
            }
        }

        $sections = [];
        $visited = [];
        foreach ($roots as $idx => $rootId) {
            $sections[] = $this->compactElement(
                $rootId,
                "sections[$idx]",
                true,
                $byId,
                $nativeToLean,
                $schema,
                $vocab,
                $problems,
                $visited
            );
        }

        return new ConversionResult([
            'area' => $area,
            'revision' => $revision,
            'sections' => $sections,
        ], $problems);
    }

    /**
     * Partition a Bricks flat element array into root section IDs (including orphans)
     * and the exact byte-for-byte slice of elements belonging to each root section.
     *
     * @param array<int, array<string, mixed>> $nativeElements
     * @return array{
     *   byId: array<string, array<string, mixed>>,
     *   roots: list<string>,
     *   subtrees: array<string, list<array<string, mixed>>>
     * }
     */
    public static function partitionByRootSections(array $nativeElements): array
    {
        $byId = [];
        $roots = [];

        foreach ($nativeElements as $el) {
            if (!is_array($el) || !isset($el['id']) || !is_scalar($el['id'])) {
                continue;
            }
            $id = (string) $el['id'];
            if ($id === '') {
                continue;
            }
            $byId[$id] = $el;
            $parent = $el['parent'] ?? 0;
            if ($parent === 0 || $parent === '0' || $parent === '') {
                $roots[] = $id;
            }
        }

        // Any element whose parent is missing (or forms a cycle) is treated as an orphan root
        foreach ($byId as $id => $el) {
            $parent = (string) ($el['parent'] ?? '0');
            if ($parent !== '0' && $parent !== '' && !isset($byId[$parent]) && !in_array($id, $roots, true)) {
                $roots[] = $id;
            }
        }

        $rootSet = array_fill_keys($roots, true);
        $subtrees = [];
        foreach ($roots as $rId) {
            $subtrees[$rId] = [];
        }

        // Map each element in $nativeElements to its top root section in exact original relative order
        foreach ($nativeElements as $el) {
            if (!is_array($el) || !isset($el['id']) || !is_scalar($el['id'])) {
                continue;
            }
            $id = (string) $el['id'];
            if ($id === '' || !isset($byId[$id])) {
                continue;
            }

            $curr = $id;
            $seen = [$curr => true];
            while (!isset($rootSet[$curr])) {
                $p = (string) ($byId[$curr]['parent'] ?? '0');
                if ($p === '0' || $p === '' || !isset($byId[$p]) || isset($seen[$p])) {
                    // Cycle or orphan fallback
                    if (!isset($rootSet[$curr])) {
                        $roots[] = $curr;
                        $rootSet[$curr] = true;
                        $subtrees[$curr] = [];
                    }
                    break;
                }
                $seen[$p] = true;
                $curr = $p;
            }

            $subtrees[$curr][] = $el;
        }

        return [
            'byId' => $byId,
            'roots' => $roots,
            'subtrees' => $subtrees,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, array{lean_type: string, spec: array<string, mixed>}> $nativeToLean
     * @param array<string, mixed> $schema
     * @param Problem[] $problems
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function compactElement(
        string $id,
        string $path,
        bool $isRoot,
        array $byId,
        array $nativeToLean,
        array $schema,
        SiteVocabulary $vocab,
        array &$problems,
        array &$visited
    ): array {
        $visited[$id] = true;
        $el = $byId[$id];
        $nativeName = (string) ($el['name'] ?? '');

        // Unknown / read-only Bricks element -> passthrough with signed subtree in extra
        if (!isset($nativeToLean[$nativeName]) || !$vocab->isNativeElementRegistered($nativeName)) {
            return $this->compactPassthrough($id, $isRoot, $byId, $vocab, $visited);
        }

        $isElementor = ($schema['builder'] ?? '') === 'elementor';
        $leanType = $nativeToLean[$nativeName]['lean_type'];
        $spec = $nativeToLean[$nativeName]['spec'];
        $isContainer = (bool) ($spec['container'] ?? false);
        if ($isElementor && $isContainer) {
            // Elementor has a single `container` native type: roots read back as sections, nested ones as containers.
            $leanType = $isRoot ? 'section' : 'container';
        }

        $out = [];
        if ($isRoot) {
            $out['ref'] = 's_' . $id;
        }
        if (isset($el['label']) && is_string($el['label']) && $el['label'] !== '') {
            $out['label'] = $el['label'];
        }
        $out['type'] = $leanType;

        $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
        $remainingSettings = $settings;

        if ($isElementor && !isset($out['label']) && isset($remainingSettings['_title']) && is_string($remainingSettings['_title']) && $remainingSettings['_title'] !== '') {
            $out = ['label' => $remainingSettings['_title']] + $out;
            if (isset($out['ref'])) {
                $out = ['ref' => $out['ref']] + $out;
            }
            unset($remainingSettings['_title']);
        }

        // 1. Extract global classes
        $classSettingKey = (string) ($schema['class_setting'] ?? '_cssGlobalClasses');
        $danglingClassIds = [];
        if ($isElementor && isset($remainingSettings[$classSettingKey]) && is_string($remainingSettings[$classSettingKey])) {
            // Elementor stores CSS classes as a space-separated string
            $remainingSettings[$classSettingKey] = preg_split('/\s+/', trim($remainingSettings[$classSettingKey]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (isset($remainingSettings[$classSettingKey]) && is_array($remainingSettings[$classSettingKey])) {
            $resolvedNames = [];
            foreach ($remainingSettings[$classSettingKey] as $cIdx => $rawClassId) {
                if (!is_scalar($rawClassId)) {
                    continue;
                }
                $classId = (string) $rawClassId;
                $className = Resolver::resolveClassId($classId, $vocab);
                if ($className !== null) {
                    $resolvedNames[] = $className;
                } else {
                    $danglingClassIds[] = $classId;
                    $problems[] = new Problem(
                        "$path.classes[$cIdx]",
                        'dangling_class',
                        sprintf("Element references unknown or deleted global class ID '%s'.", $classId),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                }
            }
            if ($resolvedNames !== []) {
                $out['classes'] = $resolvedNames;
            }
            unset($remainingSettings[$classSettingKey]);
        }

        // 2. Map type-specific settings to lean keys
        if ($isElementor) {
            $this->extractElementorSettings($leanType, $spec, $remainingSettings, $out, $vocab);
        } else {
            $this->extractLeanSettings($leanType, $spec, $remainingSettings, $out, $vocab);
        }

        // 3. Clean empty settings before building extra
        $remainingSettings = $this->stripEmptySettings($remainingSettings);
        if ($danglingClassIds !== []) {
            $remainingSettings[$classSettingKey] = $isElementor ? implode(' ', $danglingClassIds) : $danglingClassIds;
        }

        // 4. Capture any top-level native properties outside standard ones (e.g. themeStyles, cid, etc.)
        // Elementor `isInner` is derived from the parent on write, so it is not carried in extra.
        $skipTop = ['id', 'name', 'parent', 'children', 'settings', 'label'];
        if ($isElementor) {
            $skipTop[] = 'isInner';
        }
        $extraTopLevel = [];
        foreach ($el as $k => $v) {
            if (!in_array($k, $skipTop, true)) {
                $extraTopLevel[$k] = $v;
            }
        }

        if ($vocab->extraEnabled() && ($remainingSettings !== [] || $extraTopLevel !== [])) {
            $extraPayload = [
                '_native_name' => $nativeName,
            ];
            if ($remainingSettings !== []) {
                $extraPayload['settings'] = $remainingSettings;
            }
            if ($extraTopLevel !== []) {
                $extraPayload['top'] = $extraTopLevel;
            }
            $out['extra'] = ExtraCodec::encode($extraPayload, $vocab, $nativeName);
        }

        // 5. Recurse into children for container elements
        $childIds = is_array($el['children'] ?? null) ? $el['children'] : [];
        if ($isContainer) {
            $children = [];
            $cIdx = 0;
            foreach ($childIds as $rawChildId) {
                if (!is_scalar($rawChildId)) {
                    continue;
                }
                $childId = (string) $rawChildId;
                if (isset($visited[$childId]) || !isset($byId[$childId])) {
                    continue;
                }
                $children[] = $this->compactElement(
                    $childId,
                    "$path.children[$cIdx]",
                    false,
                    $byId,
                    $nativeToLean,
                    $schema,
                    $vocab,
                    $problems,
                    $visited
                );
                $cIdx++;
            }
            $out['children'] = $children;
        }

        return $out;
    }

    /**
     * Compact an unknown/read-only element (and any descendants it owns) into a passthrough node.
     *
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, true> $visited
     * @return array<string, mixed>
     */
    private function compactPassthrough(
        string $id,
        bool $isRoot,
        array $byId,
        SiteVocabulary $vocab,
        array &$visited
    ): array {
        $el = $byId[$id];
        $nativeName = (string) ($el['name'] ?? 'unknown');

        $subtree = [];
        $this->collectSubtree($id, $byId, $visited, $subtree);

        // Normalize IDs inside subtree to deterministic relative placeholders (n0, n1, ...)
        // so that compact -> expand (with new IDs) -> compact produces an identical extra token.
        $idMap = [];
        foreach ($subtree as $idx => $node) {
            $oldId = (string) ($node['id'] ?? '');
            $idMap[$oldId] = 'n' . $idx;
        }
        $normalizedSubtree = [];
        foreach ($subtree as $idx => $node) {
            $oldId = (string) ($node['id'] ?? '');
            $node['id'] = $idMap[$oldId] ?? ('n' . $idx);
            if ($idx === 0) {
                $node['parent'] = 0;
            } else {
                $oldParent = (string) ($node['parent'] ?? '');
                $node['parent'] = $idMap[$oldParent] ?? 'n0';
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $normChildren = [];
                foreach ($node['children'] as $cOld) {
                    $cOldStr = (string) $cOld;
                    $normChildren[] = $idMap[$cOldStr] ?? $cOldStr;
                }
                $node['children'] = $normChildren;
            }
            $normalizedSubtree[] = $node;
        }

        $out = [];
        if ($isRoot) {
            $out['ref'] = 's_' . $id;
        }
        if (isset($el['label']) && is_string($el['label']) && $el['label'] !== '') {
            $out['label'] = $el['label'];
        }
        $out['type'] = 'passthrough';
        $out['extra'] = ExtraCodec::encode([
            '_native_name' => $nativeName,
            '_passthrough' => true,
            '_subtree' => $normalizedSubtree,
        ], $vocab, $nativeName);

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $byId
     * @param array<string, true> $visited
     * @param array<int, array<string, mixed>> $subtree
     */
    private function collectSubtree(string $id, array $byId, array &$visited, array &$subtree): void
    {
        if (!isset($byId[$id])) {
            return;
        }
        $visited[$id] = true;
        $el = $byId[$id];
        $subtree[] = $el;

        $children = is_array($el['children'] ?? null) ? $el['children'] : [];
        foreach ($children as $rawChildId) {
            if (!is_scalar($rawChildId)) {
                continue;
            }
            $childId = (string) $rawChildId;
            if (!isset($visited[$childId])) {
                $this->collectSubtree($childId, $byId, $visited, $subtree);
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $remainingSettings
     * @param array<string, mixed> $out
     */
    private function extractLeanSettings(
        string $leanType,
        array $spec,
        array &$remainingSettings,
        array &$out,
        SiteVocabulary $vocab
    ): void {
        switch ($leanType) {
            case 'heading':
                if (isset($remainingSettings['tag']) && is_string($remainingSettings['tag'])) {
                    $rawTag = $remainingSettings['tag'];
                    $trigger = (string) ($spec['custom_tag_trigger'] ?? 'custom');
                    $customKey = (string) ($spec['custom_tag_setting'] ?? 'customTag');
                    $allowedTags = is_array($spec['allowed_tags'] ?? null)
                        ? $spec['allowed_tags']
                        : ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'];

                    if ($rawTag === $trigger && isset($remainingSettings[$customKey]) && is_string($remainingSettings[$customKey])) {
                        $customTag = trim($remainingSettings[$customKey]);
                        if (in_array($customTag, $allowedTags, true)) {
                            $out['tag'] = $customTag;
                            unset($remainingSettings['tag'], $remainingSettings[$customKey]);
                        }
                    } elseif (in_array($rawTag, $allowedTags, true)) {
                        $out['tag'] = $rawTag;
                        unset($remainingSettings['tag']);
                    }
                }
                if (isset($remainingSettings['text']) && is_string($remainingSettings['text'])) {
                    $out['text'] = $remainingSettings['text'];
                    unset($remainingSettings['text']);
                }
                break;

            case 'text':
                if (isset($remainingSettings['text']) && is_string($remainingSettings['text'])) {
                    $html = $remainingSettings['text'];
                    $out['html'] = $html;
                    unset($remainingSettings['text']);
                    // If human-edited rich text in the builder contains inline attributes (e.g. TinyMCE text-align),
                    // record its SHA-256 inside signed extra so unmodified round-trips succeed while AI edits with style= fail.
                    if (
                        preg_match('/<[a-z0-9:-]+[^>]*\s(?:style|class|color)\s*=/i', $html) === 1
                        || preg_match('/<\s*(?:style|font)\b/i', $html) === 1
                    ) {
                        $remainingSettings['_ln_html_sha256'] = hash('sha256', $html);
                    }
                }
                break;

            case 'button':
                if (isset($remainingSettings['text']) && is_string($remainingSettings['text'])) {
                    $out['text'] = $remainingSettings['text'];
                    unset($remainingSettings['text']);
                }
                if (isset($remainingSettings['link']) && is_array($remainingSettings['link'])) {
                    $linkArr = $remainingSettings['link'];
                    $linkType = (string) ($linkArr['type'] ?? '');
                    if ($linkType === 'external' && isset($linkArr['url']) && is_string($linkArr['url'])) {
                        $out['link'] = $linkArr['url'];
                        unset($linkArr['type'], $linkArr['url']);
                        if ($linkArr === []) {
                            unset($remainingSettings['link']);
                        } else {
                            // Keep extra link properties like newTab, rel in extra!
                            $remainingSettings['link'] = $linkArr;
                        }
                    }
                }
                break;

            case 'image':
                if (isset($remainingSettings['image']) && is_array($remainingSettings['image'])) {
                    $imgArr = $remainingSettings['image'];
                    if (isset($imgArr['id']) && is_numeric($imgArr['id']) && (int) $imgArr['id'] > 0) {
                        $out['media_id'] = (int) $imgArr['id'];
                        unset($imgArr['id']);
                    }
                    if (isset($imgArr['url']) && is_string($imgArr['url']) && $imgArr['url'] !== '') {
                        $out['url'] = $imgArr['url'];
                        unset($imgArr['url']);
                    }
                    // Default size 'large' doesn't need extra if no other keys exist, but keep non-default size or filename/full in extra for lossless native round-trip
                    if ($imgArr === [] || ($imgArr === ['size' => ($spec['default_size'] ?? 'large')])) {
                        unset($remainingSettings['image']);
                    } else {
                        $remainingSettings['image'] = $imgArr;
                    }
                }
                if (isset($remainingSettings['altText']) && is_string($remainingSettings['altText'])) {
                    $out['alt'] = $remainingSettings['altText'];
                    unset($remainingSettings['altText']);
                }
                break;

            case 'icon':
                if (isset($remainingSettings['icon']) && is_array($remainingSettings['icon'])) {
                    $iconArr = $remainingSettings['icon'];
                    $lib = (string) ($iconArr['library'] ?? '');
                    $ico = (string) ($iconArr['icon'] ?? '');
                    if ($lib !== '' && $ico !== '') {
                        $out['icon'] = $lib . ':' . $ico;
                        unset($iconArr['library'], $iconArr['icon']);
                        if ($iconArr === []) {
                            unset($remainingSettings['icon']);
                        } else {
                            $remainingSettings['icon'] = $iconArr;
                        }
                    }
                }
                break;

            case 'list':
                if (isset($remainingSettings['items']) && is_array($remainingSettings['items']) && array_is_list($remainingSettings['items'])) {
                    $leanItems = [];
                    foreach ($remainingSettings['items'] as $rawItem) {
                        if (!is_array($rawItem)) {
                            continue;
                        }
                        $itemRest = $rawItem;
                        $leanItem = [
                            'text' => isset($itemRest['title']) && is_string($itemRest['title']) ? $itemRest['title'] : '',
                        ];
                        unset($itemRest['title']);

                        if (isset($itemRest['link']) && is_array($itemRest['link'])) {
                            $lType = (string) ($itemRest['link']['type'] ?? '');
                            if ($lType === 'external' && isset($itemRest['link']['url']) && is_string($itemRest['link']['url'])) {
                                $leanItem['link'] = $itemRest['link']['url'];
                                unset($itemRest['link']['type'], $itemRest['link']['url']);
                                if ($itemRest['link'] === []) {
                                    unset($itemRest['link']);
                                }
                            }
                        }

                        // Drop internal repeater item ID from triggering extra if nothing else is in itemRest
                        unset($itemRest['id']);
                        if ($vocab->extraEnabled() && $itemRest !== []) {
                            $leanItem['extra'] = ExtraCodec::encode(
                                ['_native_name' => 'list_item', 'item' => $itemRest],
                                $vocab,
                                'list_item'
                            );
                        }
                        $leanItems[] = $leanItem;
                    }
                    if ($leanItems !== []) {
                        $out['items'] = $leanItems;
                    }
                    unset($remainingSettings['items']);
                }
                break;
        }
    }

    /**
     * Elementor variant of extractLeanSettings(): native keys are `title`/`header_size`, `editor`,
     * `link.url`, `image.{id,url,alt}`, `selected_icon.{library,value}` and `icon_list[]`.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $remainingSettings
     * @param array<string, mixed> $out
     */
    private function extractElementorSettings(
        string $leanType,
        array $spec,
        array &$remainingSettings,
        array &$out,
        SiteVocabulary $vocab
    ): void {
        switch ($leanType) {
            case 'heading':
                $tagKey = (string) ($spec['tag_key'] ?? 'header_size');
                $textKey = (string) ($spec['text_key'] ?? 'title');
                if (isset($remainingSettings[$tagKey]) && is_string($remainingSettings[$tagKey])) {
                    $allowedTags = is_array($spec['allowed_tags'] ?? null) ? $spec['allowed_tags'] : ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'];
                    if (in_array($remainingSettings[$tagKey], $allowedTags, true)) {
                        $out['tag'] = $remainingSettings[$tagKey];
                        unset($remainingSettings[$tagKey]);
                    }
                }
                if (isset($remainingSettings[$textKey]) && is_string($remainingSettings[$textKey])) {
                    $out['text'] = $remainingSettings[$textKey];
                    unset($remainingSettings[$textKey]);
                }
                break;

            case 'text':
                $textKey = (string) ($spec['text_key'] ?? 'editor');
                if (isset($remainingSettings[$textKey]) && is_string($remainingSettings[$textKey])) {
                    $html = $remainingSettings[$textKey];
                    $out['html'] = $html;
                    unset($remainingSettings[$textKey]);
                    if (
                        preg_match('/<[a-z0-9:-]+[^>]*\s(?:style|class|color)\s*=/i', $html) === 1
                        || preg_match('/<\s*(?:style|font)\b/i', $html) === 1
                    ) {
                        $remainingSettings['_ln_html_sha256'] = hash('sha256', $html);
                    }
                }
                break;

            case 'button':
                $textKey = (string) ($spec['text_key'] ?? 'text');
                $linkKey = (string) ($spec['link_key'] ?? 'link');
                if (isset($remainingSettings[$textKey]) && is_string($remainingSettings[$textKey])) {
                    $out['text'] = $remainingSettings[$textKey];
                    unset($remainingSettings[$textKey]);
                }
                if (isset($remainingSettings[$linkKey]) && is_array($remainingSettings[$linkKey])) {
                    $this->takeElementorLink($remainingSettings[$linkKey], $out);
                    if ($remainingSettings[$linkKey] === []) {
                        unset($remainingSettings[$linkKey]);
                    }
                }
                break;

            case 'image':
                if (isset($remainingSettings['image']) && is_array($remainingSettings['image'])) {
                    $imgArr = $remainingSettings['image'];
                    if (isset($imgArr['id']) && is_numeric($imgArr['id']) && (int) $imgArr['id'] > 0) {
                        $out['media_id'] = (int) $imgArr['id'];
                        unset($imgArr['id']);
                    }
                    if (isset($imgArr['url']) && is_string($imgArr['url']) && $imgArr['url'] !== '') {
                        $out['url'] = $imgArr['url'];
                        unset($imgArr['url']);
                    }
                    if (isset($imgArr['alt']) && is_string($imgArr['alt'])) {
                        $out['alt'] = $imgArr['alt'];
                        unset($imgArr['alt']);
                    }
                    if ($imgArr === []) {
                        unset($remainingSettings['image']);
                    } else {
                        $remainingSettings['image'] = $imgArr;
                    }
                }
                break;

            case 'icon':
                if (isset($remainingSettings['selected_icon']) && is_array($remainingSettings['selected_icon'])) {
                    $iconArr = $remainingSettings['selected_icon'];
                    $lib = (string) ($iconArr['library'] ?? '');
                    $val = $iconArr['value'] ?? '';
                    if ($lib !== '' && is_string($val) && $val !== '') {
                        $out['icon'] = $lib . ':' . $val;
                        unset($iconArr['library'], $iconArr['value']);
                        if ($iconArr === []) {
                            unset($remainingSettings['selected_icon']);
                        } else {
                            $remainingSettings['selected_icon'] = $iconArr;
                        }
                    }
                }
                break;

            case 'list':
                if (isset($remainingSettings['icon_list']) && is_array($remainingSettings['icon_list']) && array_is_list($remainingSettings['icon_list'])) {
                    $leanItems = [];
                    foreach ($remainingSettings['icon_list'] as $rawItem) {
                        if (!is_array($rawItem)) {
                            continue;
                        }
                        $itemRest = $rawItem;
                        $leanItem = [
                            'text' => isset($itemRest['text']) && is_string($itemRest['text']) ? $itemRest['text'] : '',
                        ];
                        unset($itemRest['text'], $itemRest['_id']);
                        if (isset($itemRest['link']) && is_array($itemRest['link'])) {
                            $this->takeElementorLink($itemRest['link'], $leanItem);
                            if ($itemRest['link'] === []) {
                                unset($itemRest['link']);
                            }
                        }
                        if ($vocab->extraEnabled() && $itemRest !== []) {
                            $leanItem['extra'] = ExtraCodec::encode(
                                ['_native_name' => 'list_item', 'item' => $itemRest],
                                $vocab,
                                'list_item'
                            );
                        }
                        $leanItems[] = $leanItem;
                    }
                    if ($leanItems !== []) {
                        $out['items'] = $leanItems;
                    }
                    unset($remainingSettings['icon_list']);
                }
                break;
        }
    }

    /**
     * Move an Elementor URL control value into lean `link`, dropping default empty flags.
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $out
     */
    private function takeElementorLink(array &$link, array &$out): void
    {
        if (isset($link['url']) && is_string($link['url']) && $link['url'] !== '') {
            $out['link'] = $link['url'];
        }
        unset($link['url']);
        foreach (['is_external', 'nofollow', 'custom_attributes'] as $flag) {
            if (array_key_exists($flag, $link) && ($link[$flag] === '' || $link[$flag] === false || $link[$flag] === null)) {
                unset($link[$flag]);
            }
        }
    }

    /**
     * Drop empty strings and empty arrays from remaining settings so noise doesn't bloat `extra`.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function stripEmptySettings(array $settings): array
    {
        $clean = [];
        foreach ($settings as $k => $v) {
            if ($v === '' || $v === null || $v === []) {
                continue;
            }
            $clean[$k] = $v;
        }
        return $clean;
    }
}
