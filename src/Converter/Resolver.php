<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Resolver
{
    /**
     * Resolve a class name to its builder ID (or validated class name).
     */
    public static function resolveClassName(string $className, SiteVocabulary $vocab, string $mode = 'id'): ?string
    {
        $byName = $vocab->classesByName();
        if (!isset($byName[$className])) {
            return null;
        }
        return $mode === 'name' ? $className : $byName[$className];
    }

    /**
     * Resolve a builder class ID to its human-readable class name.
     */
    public static function resolveClassId(string $classId, SiteVocabulary $vocab): ?string
    {
        $byId = $vocab->classesById();
        return $byId[$classId] ?? null;
    }

    /**
     * Suggest a closest match with Levenshtein distance <= $maxDistance.
     *
     * @param string[] $candidates
     */
    public static function closestMatch(string $input, array $candidates, int $maxDistance = 2): ?string
    {
        $best = null;
        $bestDist = $maxDistance + 1;

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $dist = levenshtein($input, $candidate);
            if ($dist >= 0 && $dist <= $maxDistance && $dist < $bestDist) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Extract all `var(--name)` variable names referenced in a string.
     *
     * @return string[] Normalized variable names with `--` prefix.
     */
    public static function extractVariableReferences(string $value): array
    {
        if (!str_contains($value, 'var(')) {
            return [];
        }
        if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) > 0) {
            return array_values(array_unique($matches[1]));
        }
        return [];
    }
}
