<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class DesignValidator
{
    public const MAX_BATCH_ITEMS = 200;
    public const ALLOWED_LAYERS = ['brand', 'scale', 'vocabulary', 'primitives'];
    public const ALLOWED_CLASS_KINDS = ['component', 'island', 'base'];

    private const STRUCTURAL_EXACT_Keywords = [
        '0', '0px', '1px', '100%', '50%', '100vw', '100vh', '100dvh', 'auto', 'none', 'normal',
        'block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'contents',
        'relative', 'absolute', 'fixed', 'sticky', 'static',
        'center', 'start', 'end', 'flex-start', 'flex-end', 'self-start', 'self-end',
        'left', 'right', 'justify', 'space-between', 'space-around', 'space-evenly', 'stretch', 'baseline',
        'row', 'row-reverse', 'column', 'column-reverse', 'wrap', 'nowrap', 'wrap-reverse',
        'pointer', 'default', 'not-allowed', 'hidden', 'visible', 'clip', 'scroll', 'overlay',
        'inherit', 'initial', 'unset', 'revert', 'transparent', 'currentcolor',
        'solid', 'dashed', 'dotted', 'double', 'underline', 'uppercase', 'lowercase', 'capitalize',
        'cover', 'contain', 'no-repeat', 'border-box', 'content-box', 'balance', 'pretty', ' ellipsis',
        '1fr', '2fr', '3fr', '4fr', 'min-content', 'max-content', 'fit-content',
        '100', '200', '300', '400', '500', '600', '700', '800', '900', 'bold', 'semibold', 'medium', 'light',
        '1', '1.1', '1.2', '1.25', '1.3', '1.4', '1.5', '1.6', '1.75', '2',
    ];

    private const UTILITY_EXACT_DENYLIST = [
        'flex', 'grid', 'block', 'inline', 'inline-block', 'hidden', 'sr-only',
        'text-left', 'text-center', 'text-right', 'text-justify',
        'uppercase', 'lowercase', 'capitalize', 'italic', 'bold', 'underline',
        'relative', 'absolute', 'fixed', 'sticky', 'w-full', 'h-full', 'mx-auto',
    ];

    private const UTILITY_PREFIX_REGEX = '/^(?:u-|p|pt|pb|pl|pr|px|py|m|mt|mb|ml|mr|mx|my|w|h|min-w|min-h|max-w|max-h|gap|gap-x|gap-y|grid-cols|col-span|row-span|text|bg|border|rounded|shadow|opacity|z|items|justify|self|content|leading|tracking|font)-(?:\d+|xs|sm|md|lg|xl|2xl|3xl|4xl|full|auto|none|screen|red|blue|green|yellow|gray|slate|zinc|neutral|stone|orange|amber|lime|emerald|teal|cyan|sky|indigo|violet|purple|fuchsia|pink|rose)(?:-\d+)?$/i';

    /**
     * Validate and merge a batch of Brand colors (`PATCH /design-system/colors`).
     *
     * @param array<int, mixed> $items
     * @param array{
     *   colors: array<string, array{name: string, css_var: string, value: string, role: string, id?: string, palette?: string}>,
     *   variables: array<string, array{name: string, value: string, layer: string, role: string, id?: string}>,
     *   classes: array<string, array{name: string, kind: string, purpose: string, declarations: array<string, string>, custom_css?: string, id?: string, settings?: array<string, mixed>}>,
     *   theme_tokens?: list<string>
     * } $currentState
     * @param array<string, list<string>> $externalUsages Map of css_var or color name => list of where used on pages/templates
     */
    public function validateColorsBatch(
        array $items,
        array $currentState,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        array $externalUsages = [],
        string $pathPrefix = 'items'
    ): ConversionResult {
        $problems = [];
        if ($items === [] || !array_is_list($items)) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'invalid_value', "Field '{$pathPrefix}' must be a non-empty list of color items."),
            ]);
        }

        if (count($items) > self::MAX_BATCH_ITEMS) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'limit_exceeded', sprintf('Batch exceeds the maximum of %d items.', self::MAX_BATCH_ITEMS)),
            ]);
        }

        $mergedColors = $currentState['colors'] ?? [];
        $seenNames = [];

        foreach ($items as $idx => $item) {
            $itemPath = "{$pathPrefix}[{$idx}]";
            if (!is_array($item)) {
                $problems[] = new Problem($itemPath, 'invalid_value', 'Each color item must be an object.');
                continue;
            }

            $rawName = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
            if ($rawName === '' || strlen($rawName) > 64 || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9 _-]*$/', $rawName) !== 1) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', "Color 'name' must be 1..64 alphanumeric characters, spaces, hyphens, or underscores.");
                continue;
            }

            $nameKey = strtolower($rawName);
            if (isset($seenNames[$nameKey])) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Duplicate color name '%s' in the same batch.", $rawName));
                continue;
            }
            $seenNames[$nameKey] = true;

            $isDelete = !empty($item['delete']);
            $existingKey = $this->findColorKeyByName($mergedColors, $rawName);

            if ($isDelete) {
                if ($existingKey === null) {
                    $problems[] = new Problem(
                        "{$itemPath}.name",
                        'not_found',
                        sprintf("Color '%s' does not exist; delete skipped.", $rawName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }

                $existingColor = $mergedColors[$existingKey];
                $cssVar = (string) ($existingColor['css_var'] ?? '');
                $force = !empty($item['force']);

                if (!$force) {
                    $dependents = $this->findVariableDependents($cssVar, $currentState['variables'] ?? [], $currentState['classes'] ?? []);
                    if (isset($externalUsages[$cssVar])) {
                        $dependents = array_merge($dependents, $externalUsages[$cssVar]);
                    }
                    if ($dependents !== []) {
                        $problems[] = new Problem(
                            "{$itemPath}.delete",
                            'token_in_use',
                            sprintf("Color '%s' (%s) is still referenced by: %s. Pass 'force': true to delete anyway.", $rawName, $cssVar, implode(', ', array_slice($dependents, 0, 5)))
                        );
                        continue;
                    }
                }

                unset($mergedColors[$existingKey]);
                continue;
            }

            $existing = $existingKey !== null ? $mergedColors[$existingKey] : null;
            $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $rawName));
            $slug = trim($slug, '-');
            $defaultCssVar = str_starts_with($slug, 'brand-') ? '--' . $slug : '--brand-' . $slug;

            $cssVar = isset($item['css_var']) && is_string($item['css_var']) && trim($item['css_var']) !== ''
                ? trim($item['css_var'])
                : ($existing['css_var'] ?? $defaultCssVar);

            if (!str_starts_with($cssVar, '--')) {
                $cssVar = '--' . $cssVar;
            }

            if (preg_match('/^--[a-zA-Z0-9_-]+$/', $cssVar) !== 1) {
                $problems[] = new Problem("{$itemPath}.css_var", 'invalid_value', sprintf("Invalid CSS variable name '%s'.", $cssVar));
                continue;
            }

            if ($this->isReadOnlyNamespace($cssVar)) {
                $problems[] = new Problem("{$itemPath}.css_var", 'invalid_value', sprintf("Variable namespace '%s' is reserved/read-only.", $cssVar));
                continue;
            }

            $rawValue = $item['value'] ?? ($item['hex'] ?? ($existing['value'] ?? null));
            if (!is_string($rawValue) || trim($rawValue) === '') {
                $problems[] = new Problem("{$itemPath}.value", 'missing_required', "Color 'value' (e.g. '#1e40af') is required.");
                continue;
            }
            $value = trim($rawValue);

            if (!$this->isSafeCssValue($value) || !$this->isValidColorLiteral($value)) {
                $problems[] = new Problem(
                    "{$itemPath}.value",
                    'invalid_value',
                    sprintf("Color value '%s' must be a valid hex (#rrggbb), rgb(), or hsl() color literal.", $value)
                );
                continue;
            }

            $role = isset($item['role']) && is_string($item['role'])
                ? substr(trim(strip_tags($item['role'])), 0, 200)
                : ($existing['role'] ?? '');

            $targetKey = $existingKey ?? $rawName;
            $mergedColors[$targetKey] = array_merge($existing ?? [], [
                'name' => $existing['name'] ?? $rawName,
                'css_var' => $cssVar,
                'value' => $value,
                'role' => $role,
            ]);
        }

        return new ConversionResult(['colors' => $mergedColors], $problems);
    }

    /**
     * Validate and merge a batch of Variables (`PATCH /design-system/variables`).
     *
     * @param array<int, mixed> $items
     * @param array<string, mixed> $currentState
     * @param array<string, list<string>> $externalUsages
     */
    public function validateVariablesBatch(
        array $items,
        array $currentState,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        array $externalUsages = [],
        string $pathPrefix = 'items'
    ): ConversionResult {
        $problems = [];
        if ($items === [] || !array_is_list($items)) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'invalid_value', "Field '{$pathPrefix}' must be a non-empty list of variable items."),
            ]);
        }

        if (count($items) > self::MAX_BATCH_ITEMS) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'limit_exceeded', sprintf('Batch exceeds the maximum of %d items.', self::MAX_BATCH_ITEMS)),
            ]);
        }

        /** @var array<string, array<string, mixed>> $mergedVars */
        $mergedVars = $currentState['variables'] ?? [];
        /** @var array<string, array<string, mixed>> $colors */
        $colors = $currentState['colors'] ?? [];
        /** @var array<string, array<string, mixed>> $classes */
        $classes = $currentState['classes'] ?? [];
        /** @var list<string> $themeTokens */
        $themeTokens = $currentState['theme_tokens'] ?? [];

        $seenNames = [];
        $touchedVarNames = [];
        $itemPathsByVar = [];

        foreach ($items as $idx => $item) {
            $itemPath = "{$pathPrefix}[{$idx}]";
            if (!is_array($item)) {
                $problems[] = new Problem($itemPath, 'invalid_value', 'Each variable item must be an object.');
                continue;
            }

            $rawName = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
            if ($rawName === '') {
                $problems[] = new Problem("{$itemPath}.name", 'missing_required', "Variable 'name' is required.");
                continue;
            }

            $varName = str_starts_with($rawName, '--') ? $rawName : '--' . $rawName;
            if (strlen($varName) > 64 || preg_match('/^--[a-zA-Z0-9_-]+$/', $varName) !== 1) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Variable name '%s' must match '--[a-zA-Z0-9_-]+'.", $varName));
                continue;
            }

            if ($this->isReadOnlyNamespace($varName)) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Variable namespace '%s' is reserved/read-only.", $varName));
                continue;
            }

            if (isset($seenNames[$varName])) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Duplicate variable name '%s' in the same batch.", $varName));
                continue;
            }
            $seenNames[$varName] = true;

            $isDelete = !empty($item['delete']);
            if ($isDelete) {
                if (!isset($mergedVars[$varName])) {
                    $problems[] = new Problem(
                        "{$itemPath}.name",
                        'not_found',
                        sprintf("Variable '%s' does not exist; delete skipped.", $varName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }

                $force = !empty($item['force']);
                if (!$force) {
                    $modifiedVarsForCheck = $mergedVars;
                    unset($modifiedVarsForCheck[$varName]);
                    $dependents = $this->findVariableDependents($varName, $modifiedVarsForCheck, $classes);
                    if (isset($externalUsages[$varName])) {
                        $dependents = array_merge($dependents, $externalUsages[$varName]);
                    }
                    if ($dependents !== []) {
                        $problems[] = new Problem(
                            "{$itemPath}.delete",
                            'token_in_use',
                            sprintf("Variable '%s' is still referenced by: %s. Pass 'force': true to delete anyway.", $varName, implode(', ', array_slice($dependents, 0, 5)))
                        );
                        continue;
                    }
                }

                unset($mergedVars[$varName]);
                continue;
            }

            $existing = $mergedVars[$varName] ?? null;

            $rawLayer = $item['layer'] ?? ($item['category'] ?? ($existing['layer'] ?? null));
            if (!is_string($rawLayer) || trim($rawLayer) === '') {
                $problems[] = new Problem("{$itemPath}.layer", 'missing_required', "Variable 'layer' (scale, vocabulary, or primitives) is required.");
                continue;
            }
            $layer = strtolower(trim($rawLayer));
            if ($layer === 'surface') {
                $layer = 'vocabulary';
            }
            if (!in_array($layer, self::ALLOWED_LAYERS, true)) {
                $problems[] = new Problem(
                    "{$itemPath}.layer",
                    'invalid_value',
                    sprintf("Invalid layer '%s'. Allowed layers: scale, vocabulary, primitives.", $rawLayer)
                );
                continue;
            }

            $rawValue = $item['value'] ?? ($existing['value'] ?? null);
            if (!is_string($rawValue) || trim($rawValue) === '') {
                $problems[] = new Problem("{$itemPath}.value", 'missing_required', "Variable 'value' is required.");
                continue;
            }
            $value = trim($rawValue);

            if (!$this->isSafeCssValue($value)) {
                $problems[] = new Problem("{$itemPath}.value", 'invalid_value', sprintf("Variable value '%s' contains unsafe or forbidden CSS characters.", $value));
                continue;
            }

            $role = isset($item['role']) && is_string($item['role'])
                ? substr(trim(strip_tags($item['role'])), 0, 200)
                : ($existing['role'] ?? '');

            $mergedVars[$varName] = array_merge($existing ?? [], [
                'name' => $varName,
                'value' => $value,
                'layer' => $layer,
                'role' => $role,
            ]);
            $touchedVarNames[$varName] = true;
            $itemPathsByVar[$varName] = $itemPath;
        }

        // Build full token lookup map (name => layer) on the merged state
        $tokenLayerMap = $this->buildTokenLayerMap($colors, $mergedVars, $themeTokens);

        // Post-merge validation: cycle detection + layer rules on touched variables and any dependents whose referenced layer changed
        foreach ($mergedVars as $varName => $varSpec) {
            $isTouched = isset($touchedVarNames[$varName]);
            $itemPath = $itemPathsByVar[$varName] ?? "variables.{$varName}";
            $value = (string) ($varSpec['value'] ?? '');
            $layer = (string) ($varSpec['layer'] ?? 'vocabulary');

            $refs = $this->extractVarReferences($value);

            // Check cycles for any variable that was touched
            if ($isTouched) {
                $cycle = $this->detectVariableCycle($varName, $mergedVars);
                if ($cycle !== null) {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'invalid_value',
                        sprintf('Circular variable reference detected: %s.', implode(' -> ', $cycle))
                    );
                    continue;
                }
            }

            if (!$isTouched) {
                // Even if this variable wasn't directly touched, verify none of its referenced tokens changed to an illegal layer
                foreach ($refs as $refName) {
                    if (isset($touchedVarNames[$refName])) {
                        $refLayer = $tokenLayerMap[$refName] ?? null;
                        if ($layer === 'primitives' && $refLayer === 'brand') {
                            $problems[] = new Problem(
                                $itemPathsByVar[$refName] ?? $pathPrefix,
                                'layer_violation',
                                sprintf("Changing '%s' to layer 'brand' breaks dependent primitive '%s' (primitives cannot read brand tokens directly).", $refName, $varName)
                            );
                        }
                    }
                }
                continue;
            }

            // Validate all var(--...) references exist and obey 4-layer hierarchy
            foreach ($refs as $refName) {
                if (!isset($tokenLayerMap[$refName])) {
                    $hint = $this->closestToken($refName, array_keys($tokenLayerMap));
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'unknown_variable',
                        sprintf("Referenced variable '%s' does not exist in the design system.", $refName),
                        $hint !== null ? sprintf("Did you mean '%s'?", $hint) : null
                    );
                    continue;
                }

                $refLayer = $tokenLayerMap[$refName];
                if ($layer === 'scale' && !in_array($refLayer, ['scale', 'theme'], true)) {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'layer_violation',
                        sprintf("Scale variable '%s' cannot reference '%s' (layer '%s'). Scale tokens may only reference scale tokens.", $varName, $refName, $refLayer)
                    );
                } elseif ($layer === 'vocabulary' && $refLayer === 'primitives') {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'layer_violation',
                        sprintf("Vocabulary variable '%s' cannot reference downstream primitive '%s'.", $varName, $refName)
                    );
                } elseif ($layer === 'primitives' && $refLayer === 'brand') {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'layer_violation',
                        sprintf("Primitive variable '%s' cannot reference brand token '%s' directly; point to a vocabulary or scale token instead.", $varName, $refName)
                    );
                }
            }

            // Check hardcoded values per layer
            if ($layer === 'scale') {
                if ($this->containsRawColor($value)) {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'hardcoded_value',
                        sprintf("Scale variable '%s' must not contain color literals ('%s'); brand colors belong in colors/brand.", $varName, $value),
                        null,
                        $tokenDiscipline
                    );
                }
            } elseif ($layer === 'vocabulary') {
                // Vocabulary tokens should point to brand colors / scale tokens, not raw hex/rgb literals
                $strippedVars = (string) preg_replace('/var\(\s*--[a-zA-Z0-9_-]+\s*\)/', '', $value);
                if ($this->containsRawColor($strippedVars)) {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'hardcoded_value',
                        sprintf("Vocabulary variable '%s' contains a hardcoded color ('%s'); reference a brand color variable (e.g. var(--brand-primary)) instead.", $varName, $value),
                        null,
                        $tokenDiscipline
                    );
                }
            } elseif ($layer === 'primitives') {
                if ($refs === [] && !in_array(strtolower($value), self::STRUCTURAL_EXACT_Keywords, true)) {
                    $problems[] = new Problem(
                        "{$itemPath}.value",
                        'hardcoded_value',
                        sprintf("Primitive variable '%s' must reference a vocabulary or scale variable via var(--...), got literal '%s'.", $varName, $value),
                        null,
                        $tokenDiscipline
                    );
                }
            }
        }

        return new ConversionResult(['variables' => $mergedVars], $problems);
    }

    /**
     * Validate and merge a batch of Global Classes (`PATCH /design-system/classes`).
     *
     * @param array<int, mixed> $items
     * @param array<string, mixed> $currentState
     * @param array<string, list<string>> $externalUsages
     */
    public function validateClassesBatch(
        array $items,
        array $currentState,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        array $externalUsages = [],
        string $pathPrefix = 'items'
    ): ConversionResult {
        $problems = [];
        if ($items === [] || !array_is_list($items)) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'invalid_value', "Field '{$pathPrefix}' must be a non-empty list of class items."),
            ]);
        }

        if (count($items) > self::MAX_BATCH_ITEMS) {
            return new ConversionResult([], [
                new Problem($pathPrefix, 'limit_exceeded', sprintf('Batch exceeds the maximum of %d items.', self::MAX_BATCH_ITEMS)),
            ]);
        }

        /** @var array<string, array<string, mixed>> $mergedClasses */
        $mergedClasses = $currentState['classes'] ?? [];
        /** @var array<string, array<string, mixed>> $colors */
        $colors = $currentState['colors'] ?? [];
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $currentState['variables'] ?? [];
        /** @var list<string> $themeTokens */
        $themeTokens = $currentState['theme_tokens'] ?? [];

        $tokenLayerMap = $this->buildTokenLayerMap($colors, $variables, $themeTokens);
        $seenNames = [];
        $deletedClasses = [];

        foreach ($items as $idx => $item) {
            $itemPath = "{$pathPrefix}[{$idx}]";
            if (!is_array($item)) {
                $problems[] = new Problem($itemPath, 'invalid_value', 'Each class item must be an object.');
                continue;
            }

            $rawName = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
            if ($rawName === '' || strlen($rawName) > 64 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $rawName) !== 1) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Class name '%s' must be a valid CSS class identifier.", $rawName));
                continue;
            }

            if (isset($seenNames[$rawName])) {
                $problems[] = new Problem("{$itemPath}.name", 'invalid_value', sprintf("Duplicate class name '%s' in the same batch.", $rawName));
                continue;
            }
            $seenNames[$rawName] = true;

            $isExisting = isset($mergedClasses[$rawName]);
            $isDelete = !empty($item['delete']);

            if ($isDelete) {
                if (!$isExisting) {
                    $problems[] = new Problem(
                        "{$itemPath}.name",
                        'not_found',
                        sprintf("Class '%s' does not exist; delete skipped.", $rawName),
                        null,
                        Problem::SEVERITY_WARNING
                    );
                    continue;
                }

                $force = !empty($item['force']);
                if (!$force && !empty($externalUsages[$rawName])) {
                    $problems[] = new Problem(
                        "{$itemPath}.delete",
                        'token_in_use',
                        sprintf("Class '%s' is currently used on: %s. Pass 'force': true to delete anyway.", $rawName, implode(', ', array_slice($externalUsages[$rawName], 0, 5)))
                    );
                    continue;
                }

                $deletedClasses[$rawName] = $mergedClasses[$rawName];
                unset($mergedClasses[$rawName]);
                continue;
            }

            // Reject utility class names for NEW classes (Overview §6.4 #1)
            if (!$isExisting && $this->isUtilityClassName($rawName)) {
                $problems[] = new Problem(
                    "{$itemPath}.name",
                    'utility_class_forbidden',
                    sprintf("Class name '%s' looks like a utility class. Only semantic role classes (base, component, island) are permitted.", $rawName)
                );
                continue;
            }

            $existing = $mergedClasses[$rawName] ?? null;

            $rawKind = $item['kind'] ?? ($existing['kind'] ?? $this->defaultClassKind($rawName));
            if (!is_string($rawKind) || !in_array($rawKind, self::ALLOWED_CLASS_KINDS, true)) {
                $problems[] = new Problem(
                    "{$itemPath}.kind",
                    'invalid_value',
                    sprintf("Class 'kind' must be one of: %s.", implode(', ', self::ALLOWED_CLASS_KINDS))
                );
                continue;
            }

            $purpose = array_key_exists('purpose', $item) && is_string($item['purpose'])
                ? substr(trim(strip_tags($item['purpose'])), 0, 200)
                : (string) ($existing['purpose'] ?? '');

            // Declarations can be passed at item.css.declarations or item.declarations.
            // If omitted on an existing class, preserve existing declarations unchanged!
            $hasDeclarationsInput = false;
            $rawDeclarations = null;
            $declPathPrefix = "{$itemPath}.css.declarations";

            if (isset($item['css']) && is_array($item['css']) && array_key_exists('declarations', $item['css'])) {
                $hasDeclarationsInput = true;
                $rawDeclarations = $item['css']['declarations'];
                $declPathPrefix = "{$itemPath}.css.declarations";
            } elseif (array_key_exists('declarations', $item)) {
                $hasDeclarationsInput = true;
                $rawDeclarations = $item['declarations'];
                $declPathPrefix = "{$itemPath}.declarations";
            }

            $declarations = is_array($existing['declarations'] ?? null) ? $existing['declarations'] : [];

            if ($hasDeclarationsInput) {
                if (!is_array($rawDeclarations) || (array_is_list($rawDeclarations) && $rawDeclarations !== [])) {
                    $problems[] = new Problem($declPathPrefix, 'invalid_value', 'Class declarations must be a JSON object mapping CSS properties to values.');
                    continue;
                }

                $validatedDecls = [];
                foreach ($rawDeclarations as $prop => $val) {
                    $propStr = trim((string) $prop);
                    $propPath = "{$declPathPrefix}.{$propStr}";

                    if (preg_match('/^-{0,2}[a-z][a-z0-9-]*$/', $propStr) !== 1) {
                        $problems[] = new Problem($propPath, 'invalid_value', sprintf("Invalid CSS property name '%s'.", $propStr));
                        continue;
                    }

                    if ($val === null || $val === '') {
                        // Null or empty string unsets the declaration on merge
                        continue;
                    }

                    if (!is_scalar($val)) {
                        $problems[] = new Problem($propPath, 'invalid_value', sprintf("CSS property '%s' value must be a string.", $propStr));
                        continue;
                    }

                    $valStr = trim((string) $val);
                    if (!$this->isSafeCssValue($valStr)) {
                        $problems[] = new Problem($propPath, 'invalid_value', sprintf("CSS property '%s' contains unsafe or forbidden characters.", $propStr));
                        continue;
                    }

                    $this->validateClassDeclaration(
                        $rawName,
                        $rawKind,
                        $propStr,
                        $valStr,
                        $propPath,
                        $tokenLayerMap,
                        $tokenDiscipline,
                        $problems
                    );

                    $validatedDecls[$propStr] = $valStr;
                }

                $declarations = $validatedDecls;
            }

            $mergedClasses[$rawName] = array_merge($existing ?? [], [
                'name' => $rawName,
                'kind' => $rawKind,
                'purpose' => $purpose,
                'declarations' => $declarations,
            ]);
        }

        return new ConversionResult([
            'classes' => $mergedClasses,
            'deleted_classes' => $deletedClasses,
        ], $problems);
    }

    /**
     * Validate a Theme Style patch (`PATCH /design-system/theme-styles/{name}`).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $currentState
     */
    public function validateThemeStylePatch(
        string $styleName,
        array $payload,
        array $currentState,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        string $pathPrefix = ''
    ): ConversionResult {
        $problems = [];
        $p = static fn(string $sub): string => $pathPrefix !== '' ? "{$pathPrefix}.{$sub}" : $sub;

        if (trim($styleName) === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $styleName) !== 1) {
            return new ConversionResult([], [
                new Problem($p('name'), 'invalid_value', "Theme style name must be alphanumeric with hyphens or underscores."),
            ]);
        }

        if (isset($payload['conditions']) || (isset($payload['settings']) && is_array($payload['settings']) && isset($payload['settings']['conditions']))) {
            $problems[] = new Problem(
                $p('settings.conditions'),
                'unknown_key',
                'Theme style conditions are read-only in v1 and cannot be modified via the API.'
            );
        }

        $settings = $payload['settings'] ?? null;
        if (!is_array($settings) || (array_is_list($settings) && $settings !== [])) {
            $problems[] = new Problem($p('settings'), 'invalid_value', "Field 'settings' must be a JSON object.");
            return new ConversionResult([], $problems);
        }

        /** @var array<string, array<string, mixed>> $colors */
        $colors = $currentState['colors'] ?? [];
        /** @var array<string, array<string, mixed>> $variables */
        $variables = $currentState['variables'] ?? [];
        /** @var list<string> $themeTokens */
        $themeTokens = $currentState['theme_tokens'] ?? [];
        $tokenLayerMap = $this->buildTokenLayerMap($colors, $variables, $themeTokens);

        $this->scanThemeStyleSettings($settings, $p('settings'), $tokenLayerMap, $tokenDiscipline, $problems);

        $label = isset($payload['label']) && is_string($payload['label']) && trim($payload['label']) !== ''
            ? substr(trim(strip_tags($payload['label'])), 0, 100)
            : null;

        return new ConversionResult([
            'name' => $styleName,
            'label' => $label,
            'settings' => $settings,
        ], $problems);
    }

    /**
     * Dry-run validation for `POST /validate` with `target: "design"`.
     * Accepts `{ colors?: [...], variables?: [...], classes?: [...], theme_styles?: {...} }` or a single PATCH payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $currentState
     */
    public function validateFullDesignPayload(
        array $payload,
        array $currentState,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        string $pathPrefix = 'payload'
    ): ConversionResult {
        $allProblems = [];
        $workingState = $currentState;
        $recognizedAny = false;

        if (isset($payload['colors']) && is_array($payload['colors'])) {
            $recognizedAny = true;
            $res = $this->validateColorsBatch($payload['colors'], $workingState, $tokenDiscipline, [], "{$pathPrefix}.colors");
            $allProblems = array_merge($allProblems, $res->problems());
            if (!$res->hasErrors()) {
                $workingState['colors'] = $res->data()['colors'];
            }
        }

        if (isset($payload['variables']) && is_array($payload['variables'])) {
            $recognizedAny = true;
            $res = $this->validateVariablesBatch($payload['variables'], $workingState, $tokenDiscipline, [], "{$pathPrefix}.variables");
            $allProblems = array_merge($allProblems, $res->problems());
            if (!$res->hasErrors()) {
                $workingState['variables'] = $res->data()['variables'];
            }
        }

        if (isset($payload['classes']) && is_array($payload['classes'])) {
            $recognizedAny = true;
            $res = $this->validateClassesBatch($payload['classes'], $workingState, $tokenDiscipline, [], "{$pathPrefix}.classes");
            $allProblems = array_merge($allProblems, $res->problems());
            if (!$res->hasErrors()) {
                $workingState['classes'] = $res->data()['classes'];
            }
        }

        if (isset($payload['theme_styles']) && is_array($payload['theme_styles'])) {
            $recognizedAny = true;
            foreach ($payload['theme_styles'] as $styleName => $stylePayload) {
                if (!is_array($stylePayload)) {
                    $allProblems[] = new Problem("{$pathPrefix}.theme_styles.{$styleName}", 'invalid_value', 'Theme style entry must be an object.');
                    continue;
                }
                $res = $this->validateThemeStylePatch((string) $styleName, $stylePayload, $workingState, $tokenDiscipline, "{$pathPrefix}.theme_styles.{$styleName}");
                $allProblems = array_merge($allProblems, $res->problems());
            }
        }

        if (!$recognizedAny) {
            $allProblems[] = new Problem(
                $pathPrefix,
                'invalid_value',
                "Design validation payload must contain at least one of: 'colors', 'variables', 'classes', 'theme_styles'."
            );
        }

        return new ConversionResult($workingState, $allProblems);
    }

    /**
     * Check whether a CSS value is free of XSS / CSS-breakout / injection vectors.
     */
    public function isSafeCssValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        // Reject HTML tags (<, >), null bytes, CSS backslash escapes (\), statement/block delimiters (;, {, })
        if (preg_match('/[<>\\\\{};\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            return false;
        }
        // Reject CSS comments (/*, */)
        if (str_contains($value, '/*') || str_contains($value, '*/')) {
            return false;
        }
        // Reject unbalanced quotes
        $singleQuotes = substr_count($value, "'");
        $doubleQuotes = substr_count($value, '"');
        if (($singleQuotes % 2 !== 0) || ($doubleQuotes % 2 !== 0)) {
            return false;
        }
        // Reject @import, @charset, expression(), javascript:, vbscript:, behavior:, -moz-binding, data:text/html
        if (preg_match('/(?:@import|@charset|expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|data\s*:\s*text\/html)/i', $value) === 1) {
            return false;
        }
        // If url(...) is used, ensure it only references safe image URLs
        if (preg_match('/url\s*\(\s*["\']?(?!(?:data:image\/|https?:\/\/|\/|\.\/))([^)"\']+)/i', $value) === 1) {
            return false;
        }
        return true;
    }

    /**
     * Extract all `--variable-name` tokens referenced inside `var(...)` in a CSS value string.
     *
     * @return list<string>
     */
    public function extractVarReferences(string $value): array
    {
        if (!str_contains($value, 'var(')) {
            return [];
        }
        if (preg_match_all('/var\(\s*(--[a-zA-Z0-9_-]+)/', $value, $matches)) {
            return array_values(array_unique($matches[1]));
        }
        return [];
    }

    /**
     * Validate a single CSS declaration inside a class according to `Overview §6.4 #2`:
     * - Component & Base classes read PRIMITIVES ONLY (`layer_violation` on brand/scale/vocabulary).
     * - Island classes rebind PRIMITIVES (`--color-*`, etc.) to `vocabulary` or `primitives` tokens.
     *
     * @param array<string, string> $tokenLayerMap
     * @param Problem[] $problems
     */
    private function validateClassDeclaration(
        string $className,
        string $kind,
        string $prop,
        string $value,
        string $propPath,
        array $tokenLayerMap,
        string $tokenDiscipline,
        array &$problems
    ): void {
        $isCustomProp = str_starts_with($prop, '--');
        $refs = $this->extractVarReferences($value);

        if ($isCustomProp) {
            if ($kind !== 'island') {
                $problems[] = new Problem(
                    $propPath,
                    'layer_violation',
                    sprintf("Class '%s' (kind '%s') cannot rebind CSS variable '%s'; only 'island' classes may rebind primitive variables.", $className, $kind, $prop)
                );
                return;
            }

            // On an island class, the property being rebound must be a primitive variable
            $targetLayer = $tokenLayerMap[$prop] ?? null;
            if ($targetLayer !== null && $targetLayer !== 'primitives') {
                $problems[] = new Problem(
                    $propPath,
                    'layer_violation',
                    sprintf("Island class '%s' may only rebind 'primitives' variables; '%s' belongs to layer '%s'.", $className, $prop, $targetLayer)
                );
            }
        }

        foreach ($refs as $refName) {
            if (!isset($tokenLayerMap[$refName])) {
                $hint = $this->closestToken($refName, array_keys($tokenLayerMap));
                $problems[] = new Problem(
                    $propPath,
                    'unknown_variable',
                    sprintf("Class '%s' references unknown variable '%s'.", $className, $refName),
                    $hint !== null ? sprintf("Did you mean '%s'?", $hint) : null
                );
                continue;
            }

            $refLayer = $tokenLayerMap[$refName];

            if ($kind === 'island' && $isCustomProp) {
                // Island rebinding a primitive: may read vocabulary or primitives (never brand directly)
                if (!in_array($refLayer, ['vocabulary', 'primitives'], true)) {
                    $problems[] = new Problem(
                        $propPath,
                        'layer_violation',
                        sprintf("Island class '%s' rebinding '%s' cannot read '%s' (layer '%s'); islands rebind primitives to 'vocabulary' tokens.", $className, $prop, $refName, $refLayer)
                    );
                }
            } else {
                // Overview §6.4 #2: Classes read PRIMITIVES ONLY (never brand, scale, or vocabulary directly)
                if ($refLayer !== 'primitives') {
                    $problems[] = new Problem(
                        $propPath,
                        'layer_violation',
                        sprintf("Class '%s' (kind '%s') cannot read '%s' (layer '%s'); classes may only read 'primitives' tokens.", $className, $kind, $refName, $refLayer)
                    );
                }
            }
        }

        // Check for hardcoded hex/rgb/hsl or hardcoded px/rem spacing/font-size outside var(...)
        $stripped = (string) preg_replace('/var\(\s*--[a-zA-Z0-9_-]+\s*(?:,\s*[^)]+)?\)/', '', $value);
        $strippedTrim = strtolower(trim($stripped));

        if ($this->containsRawColor($strippedTrim)) {
            $problems[] = new Problem(
                $propPath,
                'hardcoded_value',
                sprintf("Class '%s' declaration '%s: %s' contains a hardcoded color; use a primitive variable (e.g. var(--color-bg)).", $className, $prop, $value),
                null,
                $tokenDiscipline
            );
            return;
        }

        // Check for raw px/rem/em measurements (except structural 0, 0px, 1px)
        if ($strippedTrim !== '' && !in_array($strippedTrim, self::STRUCTURAL_EXACT_Keywords, true)) {
            if (preg_match('/(?<![a-z0-9_-])(?!0(?:px|rem|em)\b|1px\b)\d+(?:\.\d+)?(?:px|rem|em)\b/i', $strippedTrim) === 1) {
                $problems[] = new Problem(
                    $propPath,
                    'hardcoded_value',
                    sprintf("Class '%s' declaration '%s: %s' contains a hardcoded size unit; use a primitive variable instead.", $className, $prop, $value),
                    null,
                    $tokenDiscipline
                );
            }
        }
    }

    /**
     * Recursively validate theme style settings for XSS and hardcoded colors/sizes.
     *
     * @param array<string, mixed> $settings
     * @param array<string, string> $tokenLayerMap
     * @param Problem[] $problems
     */
    private function scanThemeStyleSettings(
        array $settings,
        string $currentPath,
        array $tokenLayerMap,
        string $tokenDiscipline,
        array &$problems
    ): void {
        foreach ($settings as $key => $val) {
            $keyStr = (string) $key;
            $subPath = "{$currentPath}.{$keyStr}";

            if ($keyStr === 'conditions') {
                continue;
            }

            if (is_array($val)) {
                // Bricks color object in theme styles: ['raw' => 'var(--...)', 'hex' => '#...']
                // If 'raw' uses var(--...), accept it without flagging the companion cached 'hex'
                if (isset($val['raw']) && is_string($val['raw']) && str_contains($val['raw'], 'var(')) {
                    if (!$this->isSafeCssValue($val['raw'])) {
                        $problems[] = new Problem("{$subPath}.raw", 'invalid_value', 'Unsafe CSS value in theme style color raw field.');
                        continue;
                    }
                    foreach ($this->extractVarReferences($val['raw']) as $refName) {
                        if (!isset($tokenLayerMap[$refName])) {
                            $problems[] = new Problem("{$subPath}.raw", 'unknown_variable', sprintf("Unknown variable '%s' in theme style.", $refName));
                        }
                    }
                    continue;
                }

                $this->scanThemeStyleSettings($val, $subPath, $tokenLayerMap, $tokenDiscipline, $problems);
                continue;
            }

            if (!is_string($val) || trim($val) === '') {
                continue;
            }

            // Skip font-family names, IDs, and boolean/enum flags
            if (in_array($keyStr, ['font-family', 'id', 'name', 'label', '_custom', 'main'], true)) {
                if (!$this->isSafeCssValue($val)) {
                    $problems[] = new Problem($subPath, 'invalid_value', 'Unsafe characters in theme style setting.');
                }
                continue;
            }

            if (!$this->isSafeCssValue($val)) {
                $problems[] = new Problem($subPath, 'invalid_value', sprintf("Unsafe CSS value in '%s'.", $subPath));
                continue;
            }

            foreach ($this->extractVarReferences($val) as $refName) {
                if (!isset($tokenLayerMap[$refName])) {
                    $problems[] = new Problem($subPath, 'unknown_variable', sprintf("Unknown variable '%s' in theme style.", $refName));
                }
            }

            $stripped = (string) preg_replace('/var\(\s*--[a-zA-Z0-9_-]+\s*(?:,\s*[^)]+)?\)/', '', $val);
            if ($this->containsRawColor($stripped)) {
                $problems[] = new Problem(
                    $subPath,
                    'hardcoded_value',
                    sprintf("Theme style setting '%s' contains a hardcoded color ('%s'); reference a token via var(--...) instead.", $subPath, $val),
                    null,
                    $tokenDiscipline
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $colors
     * @param array<string, array<string, mixed>> $variables
     * @param list<string> $themeTokens
     * @return array<string, string> Map of `--var-name` => layer (`brand`, `scale`, `vocabulary`, `primitives`, `theme`)
     */
    private function buildTokenLayerMap(array $colors, array $variables, array $themeTokens = []): array
    {
        $map = [];
        foreach ($themeTokens as $tName) {
            $norm = str_starts_with($tName, '--') ? $tName : '--' . $tName;
            $map[$norm] = 'theme';
        }
        foreach ($colors as $c) {
            if (isset($c['css_var']) && is_string($c['css_var']) && $c['css_var'] !== '') {
                $norm = str_starts_with($c['css_var'], '--') ? $c['css_var'] : '--' . $c['css_var'];
                $map[$norm] = 'brand';
            }
        }
        foreach ($variables as $vName => $vSpec) {
            $norm = str_starts_with((string) $vName, '--') ? (string) $vName : '--' . $vName;
            $layer = strtolower((string) ($vSpec['layer'] ?? 'vocabulary'));
            if ($layer === 'surface') {
                $layer = 'vocabulary';
            }
            $map[$norm] = $layer;
        }
        return $map;
    }

    /**
     * Detect circular references starting from `$startVar` in `$variables`.
     *
     * @param array<string, array<string, mixed>> $variables
     * @return list<string>|null Cycle path if a cycle exists, or null
     */
    private function detectVariableCycle(string $startVar, array $variables): ?array
    {
        $visited = [];
        $stack = [];

        $dfs = function (string $current) use (&$dfs, &$visited, &$stack, $variables): ?array {
            $visited[$current] = true;
            $stack[$current] = true;

            $val = (string) ($variables[$current]['value'] ?? '');
            $refs = $this->extractVarReferences($val);
            foreach ($refs as $next) {
                if (!isset($variables[$next])) {
                    continue;
                }
                if (isset($stack[$next])) {
                    $cycle = array_keys($stack);
                    $cycle[] = $next;
                    return $cycle;
                }
                if (!isset($visited[$next])) {
                    $found = $dfs($next);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            unset($stack[$current]);
            return null;
        };

        return $dfs($startVar);
    }

    /**
     * Find all variables and classes that reference `$targetVar`.
     *
     * @param array<string, array<string, mixed>> $variables
     * @param array<string, array<string, mixed>> $classes
     * @return list<string>
     */
    private function findVariableDependents(string $targetVar, array $variables, array $classes): array
    {
        if ($targetVar === '') {
            return [];
        }
        $dependents = [];
        foreach ($variables as $vName => $vSpec) {
            $refs = $this->extractVarReferences((string) ($vSpec['value'] ?? ''));
            if (in_array($targetVar, $refs, true)) {
                $dependents[] = "variable:{$vName}";
            }
        }
        foreach ($classes as $cName => $cSpec) {
            $decls = is_array($cSpec['declarations'] ?? null) ? $cSpec['declarations'] : [];
            foreach ($decls as $prop => $val) {
                $refs = $this->extractVarReferences((string) $val);
                if ($prop === $targetVar || in_array($targetVar, $refs, true)) {
                    $dependents[] = "class:{$cName}";
                    break;
                }
            }
        }
        return $dependents;
    }

    /**
     * @param array<string, array<string, mixed>> $colors
     */
    private function findColorKeyByName(array $colors, string $name): ?string
    {
        if (isset($colors[$name])) {
            return $name;
        }
        $lower = strtolower($name);
        foreach ($colors as $k => $c) {
            if (strtolower((string) $k) === $lower || strtolower((string) ($c['name'] ?? '')) === $lower) {
                return (string) $k;
            }
        }
        return null;
    }

    private function isReadOnlyNamespace(string $varName): bool
    {
        return str_starts_with($varName, '--wp--preset--')
            || str_starts_with($varName, '--bricks-')
            || str_starts_with($varName, '--e-global-');
    }

    private function isValidColorLiteral(string $value): bool
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return true;
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg+-]+\)$/i', $value) === 1) {
            return true;
        }
        return false;
    }

    private function containsRawColor(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $value) === 1) {
            return true;
        }
        if (preg_match('/\b(?:rgb|rgba|hsl|hsla)\s*\(/i', $value) === 1) {
            return true;
        }
        return false;
    }

    private function isUtilityClassName(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::UTILITY_EXACT_DENYLIST, true)) {
            return true;
        }
        return preg_match(self::UTILITY_PREFIX_REGEX, $lower) === 1;
    }

    private function defaultClassKind(string $name): string
    {
        if (in_array($name, ['section', 'container'], true)) {
            return 'base';
        }
        if (str_starts_with($name, 'island-') || str_starts_with($name, 'theme-')) {
            return 'island';
        }
        return 'component';
    }

    /**
     * @param list<string> $candidates
     */
    private function closestToken(string $needle, array $candidates): ?string
    {
        $best = null;
        $bestDist = 3;
        foreach ($candidates as $cand) {
            $d = levenshtein($needle, $cand);
            if ($d > 0 && $d <= 2 && $d < $bestDist) {
                $bestDist = $d;
                $best = $cand;
            }
        }
        return $best;
    }
}
