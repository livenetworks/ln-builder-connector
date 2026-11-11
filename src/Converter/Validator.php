<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Validator
{
    private const COMMON_KEYS = ['type', 'classes', 'label', 'children', 'ref', 'extra'];
    private const PROSE_OR_URL_KEYS = ['text', 'html', 'alt', 'label', 'link', 'url', 'icon', 'type', 'tag', 'ref', 'extra'];

    /**
     * Validate a lean document against the builder schema and site vocabulary.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @return Problem[]
     */
    public function validateDocument(array $document, array $schema, SiteVocabulary $vocab): array
    {
        $problems = [];

        $area = $document['area'] ?? 'content';
        if (!is_string($area) || !in_array($area, $vocab->allowedAreas(), true)) {
            $problems[] = new Problem(
                'area',
                'invalid_value',
                sprintf(
                    "Invalid area '%s'. Allowed areas: %s.",
                    is_scalar($area) ? (string) $area : gettype($area),
                    implode(', ', $vocab->allowedAreas())
                )
            );
        }

        if (!isset($document['sections']) || !is_array($document['sections']) || !array_is_list($document['sections'])) {
            $problems[] = new Problem(
                'sections',
                'invalid_value',
                "Document 'sections' must be a list of section elements."
            );
            return $problems;
        }

        $totalElements = 0;
        $limitReported = false;
        $seenRefs = [];

        foreach ($document['sections'] as $index => $section) {
            $path = "sections[$index]";
            if (!is_array($section)) {
                $problems[] = new Problem($path, 'invalid_value', 'Section must be an object.');
                continue;
            }

            if (isset($section['ref'])) {
                if (!is_string($section['ref']) || preg_match('/^s_[A-Za-z0-9_-]+$/', $section['ref']) !== 1) {
                    $problems[] = new Problem(
                        "$path.ref",
                        'invalid_ref',
                        "Section ref must be a string matching 's_<id>'."
                    );
                } elseif (isset($seenRefs[$section['ref']])) {
                    $problems[] = new Problem(
                        "$path.ref",
                        'invalid_ref',
                        sprintf("Duplicate section ref '%s' in document.", $section['ref'])
                    );
                } else {
                    $seenRefs[$section['ref']] = true;
                }
            }

            $this->validateElement(
                $section,
                $path,
                1,
                $schema,
                $vocab,
                $problems,
                $totalElements,
                $limitReported
            );
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed> $schema
     * @param Problem[] $problems
     */
    private function validateElement(
        array $element,
        string $path,
        int $depth,
        array $schema,
        SiteVocabulary $vocab,
        array &$problems,
        int &$totalElements,
        bool &$limitReported
    ): void {
        $totalElements++;

        if (!$limitReported && $depth > $vocab->maxDepth()) {
            $problems[] = new Problem(
                $path,
                'limit_exceeded',
                sprintf('Maximum tree depth of %d exceeded at depth %d.', $vocab->maxDepth(), $depth)
            );
            $limitReported = true;
        }

        if (!$limitReported && $totalElements > $vocab->maxElements()) {
            $problems[] = new Problem(
                $path,
                'limit_exceeded',
                sprintf('Maximum element count of %d exceeded.', $vocab->maxElements())
            );
            $limitReported = true;
        }

        if ($depth > 1 && array_key_exists('ref', $element)) {
            $problems[] = new Problem(
                "$path.ref",
                'invalid_ref',
                "The 'ref' property is only allowed on top-level sections."
            );
        }

        $type = $element['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $problems[] = new Problem("$path.type", 'type_not_allowed', 'Element is missing a valid string type.');
            return;
        }

        // Passthrough read-only element (round-trip preservation via signed extra)
        if ($type === 'passthrough') {
            $this->validatePassthroughElement($element, $path, $vocab, $problems, $totalElements, $limitReported);
            return;
        }

        $elementsMap = is_array($schema['elements'] ?? null) ? $schema['elements'] : [];
        if (!isset($elementsMap[$type]) || !is_array($elementsMap[$type])) {
            $allowedTypes = array_keys($elementsMap);
            $hint = Resolver::closestMatch($type, $allowedTypes);
            $problems[] = new Problem(
                "$path.type",
                'type_not_allowed',
                sprintf("Element type '%s' is not allowed.", $type),
                $hint !== null ? sprintf("Did you mean '%s'?", $hint) : sprintf('Allowed types: %s.', implode(', ', $allowedTypes))
            );
            return;
        }

        $typeSpec = $elementsMap[$type];
        $nativeName = (string) ($typeSpec['native'] ?? $type);

        if (!$vocab->isNativeElementRegistered($nativeName)) {
            $problems[] = new Problem(
                "$path.type",
                'type_not_allowed',
                sprintf("Element type '%s' ('%s') is disabled or not registered in the builder.", $type, $nativeName)
            );
            return;
        }

        $allowedTypeKeys = is_array($typeSpec['keys'] ?? null) ? $typeSpec['keys'] : [];
        $allowedKeys = array_merge(self::COMMON_KEYS, $allowedTypeKeys);

        foreach (array_keys($element) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $hint = Resolver::closestMatch((string) $key, $allowedKeys);
                $problems[] = new Problem(
                    "$path.$key",
                    'unknown_key',
                    sprintf("Key '%s' is not allowed on element type '%s'.", $key, $type),
                    $hint !== null ? sprintf("Did you mean '%s'?", $hint) : null
                );
            }
        }

        // Validate label
        if (array_key_exists('label', $element) && !is_string($element['label'])) {
            $problems[] = new Problem("$path.label", 'invalid_value', "Element 'label' must be a string.");
        }

        // Validate extra
        $decodedExtra = null;
        if (array_key_exists('extra', $element)) {
            if (!is_string($element['extra']) || $element['extra'] === '') {
                $problems[] = new Problem("$path.extra", 'extra_tampered', "Element 'extra' must be a non-empty signed string.");
            } else {
                $decodedExtra = ExtraCodec::decode($element['extra'], $vocab, $nativeName);
                if ($decodedExtra === null || !empty($decodedExtra['_passthrough'])) {
                    $problems[] = new Problem(
                        "$path.extra",
                        'extra_tampered',
                        "Element 'extra' failed signature verification or does not match element type '$type'.",
                        "Copy 'extra' unchanged from the read response, or omit it."
                    );
                }
            }
        }

        // Validate classes
        if (array_key_exists('classes', $element)) {
            if (!is_array($element['classes']) || !array_is_list($element['classes'])) {
                $problems[] = new Problem("$path.classes", 'invalid_value', "Element 'classes' must be a list of class names.");
            } else {
                $classMode = (string) ($schema['class_ref_mode'] ?? 'id');
                $knownClassNames = array_keys($vocab->classesByName());
                foreach ($element['classes'] as $cIdx => $className) {
                    $classPath = "$path.classes[$cIdx]";
                    if (!is_string($className) || $className === '') {
                        $problems[] = new Problem($classPath, 'invalid_value', 'Class name must be a non-empty string.');
                        continue;
                    }
                    if (Resolver::resolveClassName($className, $vocab, $classMode) === null) {
                        $hint = Resolver::closestMatch($className, $knownClassNames);
                        $problems[] = new Problem(
                            $classPath,
                            'unknown_class',
                            sprintf("Class '%s' does not exist.", $className),
                            $hint !== null ? sprintf("Did you mean '%s'?", $hint) : 'Use a class from the design context.'
                        );
                    }
                }
            }
        }

        // Validate type-specific keys
        $this->validateTypeSpecificKeys($type, $typeSpec, $element, $decodedExtra, $path, $vocab, $problems);

        // Scan any non-prose string values for variable references and hardcoded tokens
        foreach ($element as $k => $v) {
            if (in_array($k, ['type', 'classes', 'children', 'ref', 'extra', 'items'], true)) {
                continue;
            }
            if (is_string($v)) {
                $this->checkVariablesAndTokens($v, "$path.$k", !in_array($k, self::PROSE_OR_URL_KEYS, true), $vocab, $problems);
            }
        }

        // Validate children
        $isContainer = (bool) ($typeSpec['container'] ?? false);
        if (array_key_exists('children', $element)) {
            if (!$isContainer) {
                $problems[] = new Problem(
                    "$path.children",
                    'children_not_allowed',
                    sprintf("Element type '%s' is not a container and cannot have 'children'.", $type)
                );
            } elseif (!is_array($element['children']) || !array_is_list($element['children'])) {
                $problems[] = new Problem(
                    "$path.children",
                    'invalid_value',
                    "Element 'children' must be a list of child elements."
                );
            } else {
                foreach ($element['children'] as $childIdx => $child) {
                    $childPath = "$path.children[$childIdx]";
                    if (!is_array($child)) {
                        $problems[] = new Problem($childPath, 'invalid_value', 'Child element must be an object.');
                        continue;
                    }
                    $this->validateElement(
                        $child,
                        $childPath,
                        $depth + 1,
                        $schema,
                        $vocab,
                        $problems,
                        $totalElements,
                        $limitReported
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $element
     * @param Problem[] $problems
     */
    private function validatePassthroughElement(
        array $element,
        string $path,
        SiteVocabulary $vocab,
        array &$problems,
        int &$totalElements,
        bool &$limitReported
    ): void {
        $allowedKeys = ['type', 'label', 'ref', 'extra'];
        foreach (array_keys($element) as $key) {
            if ($key === 'children') {
                $problems[] = new Problem(
                    "$path.children",
                    'children_not_allowed',
                    "Passthrough elements cannot have editable 'children'."
                );
            } elseif (!in_array($key, $allowedKeys, true)) {
                $problems[] = new Problem(
                    "$path.$key",
                    'unknown_key',
                    sprintf("Key '%s' is not allowed on passthrough elements.", $key)
                );
            }
        }

        $extra = $element['extra'] ?? null;
        if (!is_string($extra) || $extra === '') {
            $problems[] = new Problem(
                "$path.type",
                'type_not_allowed',
                "Passthrough elements require an unmodified signed 'extra' token from a previous read."
            );
            return;
        }

        $decoded = ExtraCodec::decode($extra, $vocab, null);
        if ($decoded === null || empty($decoded['_passthrough']) || !is_array($decoded['_subtree'] ?? null)) {
            $problems[] = new Problem(
                "$path.extra",
                'extra_tampered',
                "Passthrough 'extra' token is invalid or tampered.",
                "Copy 'extra' unchanged from the read response."
            );
            return;
        }

        // Count any nested elements inside the passthrough subtree toward maxElements
        $subtreeCount = count($decoded['_subtree']);
        if ($subtreeCount > 1) {
            $totalElements += ($subtreeCount - 1);
            if (!$limitReported && $totalElements > $vocab->maxElements()) {
                $problems[] = new Problem(
                    $path,
                    'limit_exceeded',
                    sprintf('Maximum element count of %d exceeded.', $vocab->maxElements())
                );
                $limitReported = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $typeSpec
     * @param array<string, mixed> $element
     * @param array<string, mixed>|null $decodedExtra
     * @param Problem[] $problems
     */
    private function validateTypeSpecificKeys(
        string $type,
        array $typeSpec,
        array $element,
        ?array $decodedExtra,
        string $path,
        SiteVocabulary $vocab,
        array &$problems
    ): void {
        switch ($type) {
            case 'heading':
                if (array_key_exists('text', $element) && !is_string($element['text'])) {
                    $problems[] = new Problem("$path.text", 'invalid_value', "Heading 'text' must be a string.");
                }
                if (array_key_exists('tag', $element)) {
                    $allowedTags = is_array($typeSpec['allowed_tags'] ?? null)
                        ? $typeSpec['allowed_tags']
                        : ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'];
                    if (!is_string($element['tag']) || !in_array($element['tag'], $allowedTags, true)) {
                        $problems[] = new Problem(
                            "$path.tag",
                            'invalid_value',
                            sprintf(
                                "Invalid heading tag '%s'. Allowed tags: %s.",
                                is_scalar($element['tag']) ? (string) $element['tag'] : gettype($element['tag']),
                                implode(', ', $allowedTags)
                            )
                        );
                    }
                }
                break;

            case 'text':
                if (array_key_exists('html', $element)) {
                    if (!is_string($element['html'])) {
                        $problems[] = new Problem("$path.html", 'invalid_value', "Text 'html' must be a string.");
                    } else {
                        $preservedHash = is_string($decodedExtra['settings']['_ln_html_sha256'] ?? null)
                            ? $decodedExtra['settings']['_ln_html_sha256']
                            : null;
                        if ($preservedHash === null || !hash_equals($preservedHash, hash('sha256', $element['html']))) {
                            $this->validateRichTextHtml($element['html'], "$path.html", $vocab, $problems);
                        }
                    }
                }
                break;

            case 'button':
                if (array_key_exists('text', $element) && !is_string($element['text'])) {
                    $problems[] = new Problem("$path.text", 'invalid_value', "Button 'text' must be a string.");
                }
                if (array_key_exists('link', $element) && !is_string($element['link'])) {
                    $problems[] = new Problem("$path.link", 'invalid_value', "Button 'link' must be a string URL.");
                }
                break;

            case 'image':
                $hasMediaId = array_key_exists('media_id', $element);
                $hasUrl = array_key_exists('url', $element);
                $hasExtraImage = is_array($decodedExtra['settings']['image'] ?? null);

                if ($hasMediaId && (!is_int($element['media_id']) || $element['media_id'] <= 0)) {
                    $problems[] = new Problem("$path.media_id", 'invalid_value', "Image 'media_id' must be a positive integer.");
                }
                if ($hasUrl && (!is_string($element['url']) || trim($element['url']) === '')) {
                    $problems[] = new Problem("$path.url", 'invalid_value', "Image 'url' must be a non-empty string.");
                }
                if (array_key_exists('alt', $element) && !is_string($element['alt'])) {
                    $problems[] = new Problem("$path.alt", 'invalid_value', "Image 'alt' must be a string.");
                }
                if (!$hasMediaId && !$hasUrl && !$hasExtraImage) {
                    $problems[] = new Problem(
                        $path,
                        'invalid_value',
                        "Image element requires at least 'media_id' or 'url'."
                    );
                }
                break;

            case 'icon':
                if (!array_key_exists('icon', $element) && !is_array($decodedExtra['settings']['icon'] ?? null)) {
                    $problems[] = new Problem("$path.icon", 'invalid_value', "Icon element requires an 'icon' property ('library:name').");
                } elseif (array_key_exists('icon', $element)) {
                    if (!is_string($element['icon']) || preg_match('/^[a-z0-9_-]+:[a-z0-9_ -]+$/i', $element['icon']) !== 1) {
                        $problems[] = new Problem(
                            "$path.icon",
                            'invalid_value',
                            "Icon must be formatted as 'library:name' (e.g. 'themify:ti-star')."
                        );
                    }
                }
                break;

            case 'list':
                if (!array_key_exists('items', $element) || !is_array($element['items']) || !array_is_list($element['items']) || $element['items'] === []) {
                    $problems[] = new Problem("$path.items", 'invalid_value', "List 'items' must be a non-empty list.");
                } else {
                    foreach ($element['items'] as $idx => $item) {
                        $itemPath = "$path.items[$idx]";
                        if (!is_array($item)) {
                            $problems[] = new Problem($itemPath, 'invalid_value', 'List item must be an object.');
                            continue;
                        }
                        foreach (array_keys($item) as $itemKey) {
                            if (!in_array($itemKey, ['text', 'link', 'extra'], true)) {
                                $problems[] = new Problem(
                                    "$itemPath.$itemKey",
                                    'unknown_key',
                                    sprintf("Key '%s' is not allowed on list items.", $itemKey)
                                );
                            }
                        }
                        if (!isset($item['text']) || !is_string($item['text'])) {
                            $problems[] = new Problem("$itemPath.text", 'invalid_value', "List item requires a string 'text'.");
                        }
                        if (array_key_exists('link', $item) && !is_string($item['link'])) {
                            $problems[] = new Problem("$itemPath.link", 'invalid_value', "List item 'link' must be a string URL.");
                        }
                        if (array_key_exists('extra', $item)) {
                            if (!is_string($item['extra']) || ExtraCodec::decode($item['extra'], $vocab, 'list_item') === null) {
                                $problems[] = new Problem("$itemPath.extra", 'extra_tampered', "List item 'extra' token is invalid.");
                            }
                        }
                    }
                }
                break;
        }
    }

    /**
     * Check rich text HTML before `wp_kses_post` for inline styles, style/font tags, or class attributes.
     *
     * @param Problem[] $problems
     */
    private function validateRichTextHtml(string $html, string $path, SiteVocabulary $vocab, array &$problems): void
    {
        if (
            preg_match('/<[a-z0-9:-]+[^>]*\sstyle\s*=/i', $html) === 1
            || preg_match('/<\s*style\b/i', $html) === 1
            || preg_match('/<\s*font\b/i', $html) === 1
            || preg_match('/<[a-z0-9:-]+[^>]*\sclass\s*=/i', $html) === 1
        ) {
            $problems[] = new Problem(
                $path,
                'inline_style',
                'Inline style=, class=, <style>, or <font> tags are not allowed inside rich text HTML.',
                'Apply styling via semantic global classes on the element instead.'
            );
        }

        // Also check if an HTML tag uses a legacy color= attribute (e.g. <hr color="#ff0000">)
        if (preg_match('/<[a-z0-9:-]+[^>]*\scolor\s*=\s*["\']?([^"\'\s>]+)/i', $html, $m) === 1) {
            $problems[] = new Problem(
                $path,
                'hardcoded_value',
                sprintf("Hardcoded color '%s' is not allowed.", $m[1]),
                'Use a color variable from the design context.',
                $vocab->tokenDiscipline()
            );
        }
    }

    /**
     * Check string values for `var(--name)` references and hardcoded colors/sizes.
     *
     * @param Problem[] $problems
     */
    private function checkVariablesAndTokens(
        string $value,
        string $path,
        bool $scanHardcoded,
        SiteVocabulary $vocab,
        array &$problems
    ): void {
        $varNames = Resolver::extractVariableReferences($value);
        $knownVars = $vocab->variableNames();
        foreach ($varNames as $varName) {
            if (!$vocab->hasVariable($varName)) {
                $hint = Resolver::closestMatch($varName, $knownVars);
                $problems[] = new Problem(
                    $path,
                    'unknown_variable',
                    sprintf("Variable '%s' does not exist.", $varName),
                    $hint !== null ? sprintf("Did you mean '%s'?", $hint) : 'Use a variable from the design context.'
                );
            }
        }

        if (!$scanHardcoded) {
            return;
        }

        if (
            preg_match('/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b/', $value, $m) === 1
            || preg_match('/\b(?:rgb|rgba|hsl|hsla)\s*\(/i', $value, $m) === 1
            || preg_match('/\b\d+(?:\.\d+)?(?:px|rem|em)\b/i', $value, $m) === 1
        ) {
            $problems[] = new Problem(
                $path,
                'hardcoded_value',
                sprintf("Inline hardcoded value '%s' is not allowed.", $m[0]),
                'Use a token variable from the design context.',
                $vocab->tokenDiscipline()
            );
        }
    }
}
