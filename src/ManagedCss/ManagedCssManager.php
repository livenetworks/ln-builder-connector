<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\ManagedCss;

final class ManagedCssManager
{
    public const OPTION_MANAGED_CSS = 'ln_builder_managed_css';
    public const START_MARKER = '/* ln-builder:start */';
    public const END_MARKER = '/* ln-builder:end */';

    /**
     * Get the authoritative managed CSS state.
     *
     * @return array{variables: list<array<string, mixed>>, classes: list<array<string, mixed>>, block_hash: string}
     */
    public static function get(): array
    {
        $raw = function_exists('get_option') ? get_option(self::OPTION_MANAGED_CSS, []) : [];
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'variables' => is_array($raw['variables'] ?? null) ? array_values($raw['variables']) : [],
            'classes' => is_array($raw['classes'] ?? null) ? array_values($raw['classes']) : [],
            'block_hash' => is_string($raw['block_hash'] ?? null) ? $raw['block_hash'] : '',
        ];
    }

    /**
     * Save the authoritative managed CSS state.
     *
     * @param array{variables?: list<array<string, mixed>>, classes?: list<array<string, mixed>>} $data
     */
    public static function set(array $data): void
    {
        $current = self::get();
        $variables = $data['variables'] ?? $current['variables'];
        $classes = $data['classes'] ?? $current['classes'];

        $css = self::renderCss($variables, $classes);
        $hash = hash('sha256', $css);

        if (function_exists('update_option')) {
            update_option(self::OPTION_MANAGED_CSS, [
                'variables' => $variables,
                'classes' => $classes,
                'block_hash' => $hash,
            ], true);
        }
    }

    /**
     * Render the raw CSS block from variables and classes.
     *
     * @param list<array<string, mixed>> $variables
     * @param list<array<string, mixed>> $classes
     */
    public static function renderCss(array $variables, array $classes): string
    {
        $lines = [];

        // Render CSS variables under :root
        if ($variables !== []) {
            $lines[] = ':root {';
            foreach ($variables as $var) {
                $name = (string) ($var['name'] ?? '');
                $val = (string) ($var['value'] ?? '');
                if ($name !== '' && $val !== '') {
                    $lines[] = sprintf('  %s: %s;', $name, $val);
                }
            }
            $lines[] = '}';
        }

        // Render semantic classes
        foreach ($classes as $class) {
            $name = (string) ($class['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $selector = '.' . ltrim($name, '.');
            $styles = is_array($class['styles'] ?? null) ? $class['styles'] : ($class['css'] ?? []);

            if (is_array($styles) && $styles !== []) {
                $lines[] = $selector . ' {';
                foreach ($styles as $prop => $val) {
                    if (is_string($prop) && is_scalar($val)) {
                        $lines[] = sprintf('  %s: %s;', $prop, (string) $val);
                    }
                }
                $lines[] = '}';
            }

            // Append raw hand-authored custom CSS if present
            if (!empty($class['_cssCustom']) && is_string($class['_cssCustom'])) {
                $lines[] = trim($class['_cssCustom']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build the marked CSS block including start and end markers.
     */
    public static function buildMarkedBlock(array $variables, array $classes): string
    {
        $css = self::renderCss($variables, $classes);
        return self::START_MARKER . "\n" . $css . "\n" . self::END_MARKER;
    }

    /**
     * Inject or update the managed CSS block within existing Site Custom CSS.
     */
    public static function injectIntoCustomCss(string $existingCustomCss, array $variables, array $classes): string
    {
        $markedBlock = self::buildMarkedBlock($variables, $classes);

        $startPos = strpos($existingCustomCss, self::START_MARKER);
        $endPos = strpos($existingCustomCss, self::END_MARKER);

        if ($startPos !== false && $endPos !== false && $endPos >= $startPos) {
            $before = substr($existingCustomCss, 0, $startPos);
            $after = substr($existingCustomCss, $endPos + strlen(self::END_MARKER));
            return rtrim($before) . "\n\n" . $markedBlock . "\n\n" . ltrim($after);
        }

        // Append if not found
        $trimmed = trim($existingCustomCss);
        return $trimmed === '' ? $markedBlock : $trimmed . "\n\n" . $markedBlock;
    }

    /**
     * Extract the raw CSS content between the managed markers from existing Custom CSS.
     */
    public static function extractFromCustomCss(string $existingCustomCss): ?string
    {
        $startPos = strpos($existingCustomCss, self::START_MARKER);
        $endPos = strpos($existingCustomCss, self::END_MARKER);

        if ($startPos === false || $endPos === false || $endPos < $startPos) {
            return null;
        }

        $startOffset = $startPos + strlen(self::START_MARKER);
        return trim(substr($existingCustomCss, $startOffset, $endPos - $startOffset));
    }

    /**
     * Check whether the managed CSS block in the kit has drifted from the authoritative model.
     *
     * @return bool True if there is a conflict/drift, false if consistent.
     */
    public static function hasConflict(string $existingCustomCss): bool
    {
        $state = self::get();
        if ($state['variables'] === [] && $state['classes'] === []) {
            return false;
        }

        $currentInKit = self::extractFromCustomCss($existingCustomCss);
        if ($currentInKit === null) {
            return false;
        }

        $currentHash = hash('sha256', $currentInKit);
        $expectedHash = $state['block_hash'];

        return $expectedHash !== '' && !hash_equals($expectedHash, $currentHash);
    }
}
