<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Expander
{
    /** @var callable(): string */
    private $idGenerator;

    /**
     * @param (callable(): string)|null $idGenerator Optional custom ID generator for deterministic tests.
     */
    public function __construct(?callable $idGenerator = null)
    {
        $this->idGenerator = $idGenerator ?? [self::class, 'defaultIdGenerator'];
    }

    /**
     * Validate and expand a lean document into builder native elements (Bricks flat array).
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @param bool $preserveSectionRefs False on create (`POST /templates`), true on in-place replace (`PUT`/`PATCH`).
     * @param list<string> $reservedIds Existing element IDs in the document that must not be reused for new elements.
     * @param list<string>|null $allowedExistingSectionIds When non-null (e.g. `PUT`), any section `ref` must exist in this list or `invalid_ref` is emitted.
     */
    public function expand(
        array $document,
        array $schema,
        SiteVocabulary $vocab,
        bool $preserveSectionRefs = false,
        array $reservedIds = [],
        ?array $allowedExistingSectionIds = null
    ): ConversionResult {
        $validator = new Validator();
        $problems = $validator->validateDocument($document, $schema, $vocab);

        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];

        if ($allowedExistingSectionIds !== null) {
            $allowedSet = array_fill_keys($allowedExistingSectionIds, true);
            $seenRefIds = [];
            foreach ($sections as $sIdx => $section) {
                if (!is_array($section) || !isset($section['ref'])) {
                    continue;
                }
                $rawRef = $section['ref'];
                if (!is_string($rawRef) || !str_starts_with($rawRef, 's_') || strlen($rawRef) <= 2) {
                    $problems[] = new Problem(
                        "sections[{$sIdx}].ref",
                        'invalid_ref',
                        sprintf("Invalid section ref '%s'.", is_scalar($rawRef) ? (string) $rawRef : gettype($rawRef))
                    );
                    continue;
                }
                $secId = substr($rawRef, 2);
                if (!isset($allowedSet[$secId])) {
                    $problems[] = new Problem(
                        "sections[{$sIdx}].ref",
                        'invalid_ref',
                        sprintf("Section ref '%s' does not exist in the target document.", $rawRef)
                    );
                    continue;
                }
                if (isset($seenRefIds[$secId])) {
                    $problems[] = new Problem(
                        "sections[{$sIdx}].ref",
                        'invalid_ref',
                        sprintf("Section ref '%s' is referenced more than once in the document.", $rawRef)
                    );
                    continue;
                }
                $seenRefIds[$secId] = true;
            }
        }

        foreach ($problems as $problem) {
            if ($problem->isError()) {
                return new ConversionResult([], $problems);
            }
        }

        $usedIds = [];
        $preassignedRootIds = [];
        if ($preserveSectionRefs) {
            foreach ($sections as $sIdx => $section) {
                if (!is_array($section)) {
                    continue;
                }
                if (isset($section['ref']) && is_string($section['ref']) && str_starts_with($section['ref'], 's_')) {
                    $candidate = substr($section['ref'], 2);
                    if ($candidate !== '' && !isset($usedIds[$candidate])) {
                        $preassignedRootIds[$sIdx] = $candidate;
                        $usedIds[$candidate] = true;
                    }
                }
            }
        }

        foreach ($reservedIds as $rId) {
            if (is_string($rId) && $rId !== '') {
                $usedIds[$rId] = true;
            }
        }

        $flatElements = [];

        foreach ($sections as $sIdx => $section) {
            if (!is_array($section)) {
                continue;
            }
            $this->expandElement(
                $section,
                0,
                true,
                $preserveSectionRefs,
                $schema,
                $vocab,
                $usedIds,
                $flatElements,
                $preassignedRootIds[$sIdx] ?? null
            );
        }

        return new ConversionResult($flatElements, $problems);
    }

    /**
     * @param array<string, mixed> $element
     * @param int|string $parentId
     * @param array<string, mixed> $schema
     * @param array<string, true> $usedIds
     * @param array<int, array<string, mixed>> $flatElements
     */
    private function expandElement(
        array $element,
        $parentId,
        bool $isRoot,
        bool $preserveSectionRefs,
        array $schema,
        SiteVocabulary $vocab,
        array &$usedIds,
        array &$flatElements,
        ?string $preassignedRootId = null
    ): string {
        $type = (string) $element['type'];

        if ($type === 'passthrough') {
            return $this->expandPassthrough(
                $element,
                $parentId,
                $isRoot,
                $preserveSectionRefs,
                $vocab,
                $usedIds,
                $flatElements,
                $preassignedRootId
            );
        }

        $typeSpec = $schema['elements'][$type];
        $nativeName = (string) ($typeSpec['native'] ?? $type);

        // Determine element ID
        $id = null;
        if ($isRoot && $preassignedRootId !== null && $preassignedRootId !== '') {
            $id = $preassignedRootId;
        } elseif ($isRoot && $preserveSectionRefs && isset($element['ref']) && is_string($element['ref']) && str_starts_with($element['ref'], 's_')) {
            $candidate = substr($element['ref'], 2);
            if ($candidate !== '' && !isset($usedIds[$candidate])) {
                $id = $candidate;
            }
        }
        if ($id === null) {
            $id = $this->nextUniqueId($usedIds);
        }
        $usedIds[$id] = true;

        // Decode extra if present
        $decodedExtra = null;
        if (isset($element['extra']) && is_string($element['extra'])) {
            $decodedExtra = ExtraCodec::decode($element['extra'], $vocab, $nativeName);
        }

        $settings = is_array($decodedExtra['settings'] ?? null) ? $decodedExtra['settings'] : [];
        $topExtra = is_array($decodedExtra['top'] ?? null) ? $decodedExtra['top'] : [];

        // Map classes -> _cssGlobalClasses (merged with any dangling classes preserved in extra)
        $classSettingKey = (string) ($schema['class_setting'] ?? '_cssGlobalClasses');
        $classMode = (string) ($schema['class_ref_mode'] ?? 'id');
        $danglingIds = isset($settings[$classSettingKey]) && is_array($settings[$classSettingKey])
            ? $settings[$classSettingKey]
            : [];

        if (isset($element['classes']) && is_array($element['classes'])) {
            $resolvedClasses = [];
            foreach ($element['classes'] as $className) {
                $resolved = Resolver::resolveClassName((string) $className, $vocab, $classMode);
                if ($resolved !== null) {
                    $resolvedClasses[] = $resolved;
                }
            }
            $mergedClasses = array_values(array_unique(array_merge($resolvedClasses, $danglingIds)));
            if ($mergedClasses !== []) {
                $settings[$classSettingKey] = $mergedClasses;
            } else {
                unset($settings[$classSettingKey]);
            }
        } elseif ($danglingIds !== []) {
            $settings[$classSettingKey] = $danglingIds;
        }

        // Map lean keys -> native settings (lean wins over extra on overlap)
        $this->applyLeanSettings($type, $typeSpec, $element, $settings, $vocab, $usedIds);

        // Reserve slot in flatElements so parent precedes children in the flat array
        $currentIndex = count($flatElements);
        $flatElements[$currentIndex] = [];

        $childIds = [];
        if (isset($element['children']) && is_array($element['children'])) {
            foreach ($element['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childIds[] = $this->expandElement(
                    $child,
                    $id,
                    false,
                    false,
                    $schema,
                    $vocab,
                    $usedIds,
                    $flatElements
                );
            }
        }

        $nativeNode = [
            'id' => $id,
            'name' => $nativeName,
            'parent' => $isRoot ? 0 : (string) $parentId,
            'children' => $childIds,
            'settings' => $settings,
        ];

        if (isset($element['label']) && is_string($element['label']) && $element['label'] !== '') {
            $nativeNode['label'] = $element['label'];
        }

        foreach ($topExtra as $k => $v) {
            if (!isset($nativeNode[$k])) {
                $nativeNode[$k] = $v;
            }
        }

        $flatElements[$currentIndex] = $nativeNode;

        return $id;
    }

    /**
     * Expand a passthrough element and its subtree, remapping IDs so copied passthrough nodes never collide.
     *
     * @param array<string, mixed> $element
     * @param int|string $parentId
     * @param array<string, true> $usedIds
     * @param array<int, array<string, mixed>> $flatElements
     */
    private function expandPassthrough(
        array $element,
        $parentId,
        bool $isRoot,
        bool $preserveSectionRefs,
        SiteVocabulary $vocab,
        array &$usedIds,
        array &$flatElements,
        ?string $preassignedRootId = null
    ): string {
        $decoded = ExtraCodec::decode((string) $element['extra'], $vocab, null);
        $subtree = is_array($decoded['_subtree'] ?? null) ? $decoded['_subtree'] : [];

        $idMap = [];
        foreach ($subtree as $idx => $node) {
            $oldId = (string) ($node['id'] ?? '');
            if ($idx === 0 && $isRoot && $preassignedRootId !== null && $preassignedRootId !== '') {
                $idMap[$oldId] = $preassignedRootId;
                $usedIds[$preassignedRootId] = true;
                continue;
            }
            if ($idx === 0 && $isRoot && $preserveSectionRefs && isset($element['ref']) && is_string($element['ref']) && str_starts_with($element['ref'], 's_')) {
                $candidate = substr($element['ref'], 2);
                if ($candidate !== '' && !isset($usedIds[$candidate])) {
                    $idMap[$oldId] = $candidate;
                    $usedIds[$candidate] = true;
                    continue;
                }
            }
            $newId = $this->nextUniqueId($usedIds);
            $idMap[$oldId] = $newId;
            $usedIds[$newId] = true;
        }

        $rootNewId = '';
        foreach ($subtree as $idx => $node) {
            $oldId = (string) ($node['id'] ?? '');
            $newId = $idMap[$oldId] ?? $this->nextUniqueId($usedIds);
            if ($idx === 0) {
                $rootNewId = $newId;
                $node['parent'] = $isRoot ? 0 : (string) $parentId;
                if (isset($element['label']) && is_string($element['label']) && $element['label'] !== '') {
                    $node['label'] = $element['label'];
                }
            } else {
                $oldParent = (string) ($node['parent'] ?? '');
                $node['parent'] = $idMap[$oldParent] ?? $rootNewId;
            }
            $node['id'] = $newId;

            if (isset($node['children']) && is_array($node['children'])) {
                $newChildren = [];
                foreach ($node['children'] as $cOld) {
                    $cOldStr = (string) $cOld;
                    $newChildren[] = $idMap[$cOldStr] ?? $cOldStr;
                }
                $node['children'] = $newChildren;
            }

            $flatElements[] = $node;
        }

        return $rootNewId;
    }

    /**
     * @param array<string, mixed> $typeSpec
     * @param array<string, mixed> $element
     * @param array<string, mixed> $settings
     * @param array<string, true> $usedIds
     */
    private function applyLeanSettings(
        string $type,
        array $typeSpec,
        array $element,
        array &$settings,
        SiteVocabulary $vocab,
        array &$usedIds
    ): void {
        switch ($type) {
            case 'heading':
                if (isset($element['text']) && is_string($element['text'])) {
                    $settings['text'] = $element['text'];
                }
                if (isset($element['tag']) && is_string($element['tag'])) {
                    $tag = $element['tag'];
                    $nativeTags = is_array($typeSpec['native_tags'] ?? null)
                        ? $typeSpec['native_tags']
                        : ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
                    $customTrigger = (string) ($typeSpec['custom_tag_trigger'] ?? 'custom');
                    $customKey = (string) ($typeSpec['custom_tag_setting'] ?? 'customTag');

                    if (in_array($tag, $nativeTags, true)) {
                        $settings['tag'] = $tag;
                        unset($settings[$customKey]);
                    } else {
                        $settings['tag'] = $customTrigger;
                        $settings[$customKey] = $tag;
                    }
                }
                break;

            case 'text':
                if (isset($element['html']) && is_string($element['html'])) {
                    $settings['text'] = $element['html'];
                }
                unset($settings['_ln_html_sha256']);
                break;

            case 'button':
                if (isset($element['text']) && is_string($element['text'])) {
                    $settings['text'] = $element['text'];
                }
                if (isset($element['link']) && is_string($element['link'])) {
                    $existingLink = isset($settings['link']) && is_array($settings['link']) ? $settings['link'] : [];
                    $existingLink['type'] = 'external';
                    $existingLink['url'] = $element['link'];
                    $settings['link'] = $existingLink;
                }
                break;

            case 'image':
                $existingImg = isset($settings['image']) && is_array($settings['image']) ? $settings['image'] : [];
                if (isset($element['media_id']) && is_int($element['media_id'])) {
                    $existingImg['id'] = $element['media_id'];
                }
                if (isset($element['url']) && is_string($element['url'])) {
                    $existingImg['url'] = $element['url'];
                }
                if ($existingImg !== [] && !isset($existingImg['size'])) {
                    $existingImg['size'] = (string) ($typeSpec['default_size'] ?? 'large');
                }
                if ($existingImg !== []) {
                    $settings['image'] = $existingImg;
                }
                if (isset($element['alt']) && is_string($element['alt'])) {
                    $settings['altText'] = $element['alt'];
                }
                break;

            case 'icon':
                if (isset($element['icon']) && is_string($element['icon'])) {
                    $parts = explode(':', $element['icon'], 2);
                    $existingIcon = isset($settings['icon']) && is_array($settings['icon']) ? $settings['icon'] : [];
                    $existingIcon['library'] = $parts[0];
                    $existingIcon['icon'] = $parts[1] ?? '';
                    $settings['icon'] = $existingIcon;
                }
                break;

            case 'list':
                if (isset($element['items']) && is_array($element['items'])) {
                    $nativeItems = [];
                    foreach ($element['items'] as $leanItem) {
                        if (!is_array($leanItem)) {
                            continue;
                        }
                        $nativeItem = [];
                        if (isset($leanItem['extra']) && is_string($leanItem['extra'])) {
                            $decodedItem = ExtraCodec::decode($leanItem['extra'], $vocab, 'list_item');
                            if (is_array($decodedItem['item'] ?? null)) {
                                $nativeItem = $decodedItem['item'];
                            }
                        }
                        $nativeItem['id'] = $this->nextUniqueId($usedIds);
                        $nativeItem['title'] = (string) ($leanItem['text'] ?? '');
                        if (isset($leanItem['link']) && is_string($leanItem['link'])) {
                            $existingLink = isset($nativeItem['link']) && is_array($nativeItem['link']) ? $nativeItem['link'] : [];
                            $existingLink['type'] = 'external';
                            $existingLink['url'] = $leanItem['link'];
                            $nativeItem['link'] = $existingLink;
                        }
                        $nativeItems[] = $nativeItem;
                    }
                    $settings['items'] = $nativeItems;
                }
                break;
        }
    }

    /**
     * @param array<string, true> $usedIds
     */
    private function nextUniqueId(array &$usedIds): string
    {
        $gen = $this->idGenerator;
        for ($i = 0; $i < 1000; $i++) {
            $candidate = (string) $gen();
            if ($candidate !== '' && !isset($usedIds[$candidate])) {
                $usedIds[$candidate] = true;
                return $candidate;
            }
        }
        throw new \RuntimeException('Failed to generate a unique element ID.');
    }

    public static function defaultIdGenerator(): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = $letters[random_int(0, 25)];
        for ($i = 0; $i < 5; $i++) {
            $id .= $chars[random_int(0, 35)];
        }
        return $id;
    }
}
