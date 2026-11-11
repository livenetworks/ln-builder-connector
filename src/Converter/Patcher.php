<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Patcher
{
    public const MAX_OPS = 50;

    private const ALLOWED_OP_KEYS = [
        'insert' => ['op', 'after', 'section'],
        'replace' => ['op', 'ref', 'section'],
        'move' => ['op', 'ref', 'after'],
        'remove' => ['op', 'ref'],
    ];

    /** @var (callable(): string)|null */
    private $idGenerator;

    /** @var array<string, true> */
    private array $modifiedElementIds = [];

    /**
     * @param (callable(): string)|null $idGenerator
     */
    public function __construct(?callable $idGenerator = null)
    {
        $this->idGenerator = $idGenerator;
    }

    /**
     * Return the map of element IDs created or replaced during the most recent `apply()` call.
     *
     * @return array<string, true>
     */
    public function modifiedElementIds(): array
    {
        return $this->modifiedElementIds;
    }

    /**
     * Apply a list of section patch operations atomically to a Bricks flat element array.
     * Untouched sections are preserved byte-for-byte.
     *
     * @param array<int, array<string, mixed>> $storedElements
     * @param array<int, mixed> $ops
     * @param array<string, mixed> $schema
     */
    public function apply(
        array $storedElements,
        array $ops,
        array $schema,
        SiteVocabulary $vocab,
        string $area = 'content'
    ): ConversionResult {
        $this->modifiedElementIds = [];
        $problems = [];

        if ($ops === [] || !array_is_list($ops)) {
            return new ConversionResult([], [
                new Problem('ops', 'invalid_value', "Field 'ops' must be a non-empty list of patch operations."),
            ]);
        }

        if (count($ops) > self::MAX_OPS) {
            return new ConversionResult([], [
                new Problem(
                    'ops',
                    'limit_exceeded',
                    sprintf('Patch batch exceeds the maximum of %d operations.', self::MAX_OPS)
                ),
            ]);
        }

        $partition = Compactor::partitionByRootSections($storedElements);
        $currentRoots = $partition['roots'];
        $currentSubtrees = $partition['subtrees'];

        $usedIds = [];
        foreach (array_keys($partition['byId']) as $existingId) {
            $usedIds[(string) $existingId] = true;
        }

        $replacedRefs = [];
        $movedRefs = [];
        $removedRefs = [];

        foreach ($ops as $idx => $op) {
            $opPath = "ops[{$idx}]";
            if (!is_array($op)) {
                $problems[] = new Problem($opPath, 'invalid_value', 'Each patch operation must be an object.');
                continue;
            }

            $opType = $op['op'] ?? null;
            if (!is_string($opType) || !isset(self::ALLOWED_OP_KEYS[$opType])) {
                $problems[] = new Problem(
                    "{$opPath}.op",
                    'invalid_value',
                    "Operation 'op' must be one of: insert, replace, move, remove."
                );
                continue;
            }

            $allowedKeys = self::ALLOWED_OP_KEYS[$opType];
            foreach (array_keys($op) as $k) {
                if (!in_array((string) $k, $allowedKeys, true)) {
                    $problems[] = new Problem(
                        "{$opPath}.{$k}",
                        'unknown_key',
                        sprintf("Key '%s' is not allowed on '%s' operation.", (string) $k, $opType)
                    );
                }
            }

            switch ($opType) {
                case 'insert':
                    if (!array_key_exists('after', $op)) {
                        $problems[] = new Problem("{$opPath}.after", 'invalid_value', "Insert operation requires 'after' (section ref or null).");
                        break;
                    }
                    $afterRef = $op['after'];
                    $afterId = null;
                    if ($afterRef !== null) {
                        $afterId = $this->resolveExistingRootId($afterRef, $currentRoots);
                        if ($afterId === null) {
                            $problems[] = new Problem(
                                "{$opPath}.after",
                                'invalid_ref',
                                sprintf("Target 'after' ref '%s' does not exist.", is_scalar($afterRef) ? (string) $afterRef : gettype($afterRef))
                            );
                            break;
                        }
                    }

                    $section = $op['section'] ?? null;
                    if (!is_array($section)) {
                        $problems[] = new Problem("{$opPath}.section", 'invalid_value', "Insert operation requires a 'section' object.");
                        break;
                    }

                    if (isset($section['ref'])) {
                        $problems[] = new Problem("{$opPath}.section.ref", 'invalid_value', "Inserted section must not specify a 'ref'.");
                        break;
                    }

                    $expander = new Expander($this->idGenerator);
                    $expRes = $expander->expand(
                        ['area' => $area, 'sections' => [$section]],
                        $schema,
                        $vocab,
                        false,
                        array_keys($usedIds)
                    );

                    foreach ($expRes->problems() as $p) {
                        $problems[] = $this->rewriteSectionProblemPath($p, "{$opPath}.section");
                    }

                    if ($expRes->hasErrors()) {
                        break;
                    }

                    /** @var list<array<string, mixed>> $expandedSlice */
                    $expandedSlice = array_values($expRes->data());
                    if ($expandedSlice === []) {
                        break;
                    }

                    $newRootId = (string) $expandedSlice[0]['id'];
                    foreach ($expandedSlice as $el) {
                        $elId = (string) ($el['id'] ?? '');
                        if ($elId !== '') {
                            $usedIds[$elId] = true;
                            $this->modifiedElementIds[$elId] = true;
                        }
                    }

                    $currentSubtrees[$newRootId] = $expandedSlice;
                    $currentRoots = $this->insertRootId($currentRoots, $newRootId, $afterId);
                    break;

                case 'replace':
                    $ref = $op['ref'] ?? null;
                    $targetId = $this->resolveExistingRootId($ref, $currentRoots);
                    if ($targetId === null) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Target section ref '%s' does not exist.", is_scalar($ref) ? (string) $ref : gettype($ref))
                        );
                        break;
                    }

                    if (isset($replacedRefs[$targetId]) || isset($removedRefs[$targetId])) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Section ref 's_%s' cannot be replaced more than once or combined with remove in the same patch.", $targetId)
                        );
                        break;
                    }

                    $section = $op['section'] ?? null;
                    if (!is_array($section)) {
                        $problems[] = new Problem("{$opPath}.section", 'invalid_value', "Replace operation requires a 'section' object.");
                        break;
                    }

                    if (isset($section['ref']) && $section['ref'] !== ('s_' . $targetId)) {
                        $problems[] = new Problem(
                            "{$opPath}.section.ref",
                            'invalid_ref',
                            sprintf("Section 'ref' ('%s') does not match operation 'ref' ('s_%s').", is_scalar($section['ref']) ? (string) $section['ref'] : gettype($section['ref']), $targetId)
                        );
                        break;
                    }

                    $replacedRefs[$targetId] = $idx;
                    $section['ref'] = 's_' . $targetId;

                    $reservedForReplace = $usedIds;
                    unset($reservedForReplace[$targetId]);

                    $expander = new Expander($this->idGenerator);
                    $expRes = $expander->expand(
                        ['area' => $area, 'sections' => [$section]],
                        $schema,
                        $vocab,
                        true,
                        array_keys($reservedForReplace)
                    );

                    foreach ($expRes->problems() as $p) {
                        $problems[] = $this->rewriteSectionProblemPath($p, "{$opPath}.section");
                    }

                    if ($expRes->hasErrors()) {
                        break;
                    }

                    /** @var list<array<string, mixed>> $expandedSlice */
                    $expandedSlice = array_values($expRes->data());
                    foreach ($expandedSlice as $el) {
                        $elId = (string) ($el['id'] ?? '');
                        if ($elId !== '') {
                            $usedIds[$elId] = true;
                            $this->modifiedElementIds[$elId] = true;
                        }
                    }

                    $currentSubtrees[$targetId] = $expandedSlice;
                    break;

                case 'move':
                    $ref = $op['ref'] ?? null;
                    $targetId = $this->resolveExistingRootId($ref, $currentRoots);
                    if ($targetId === null) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Target section ref '%s' does not exist.", is_scalar($ref) ? (string) $ref : gettype($ref))
                        );
                        break;
                    }

                    if (isset($movedRefs[$targetId]) || isset($removedRefs[$targetId])) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Section ref 's_%s' cannot be moved more than once or combined with remove in the same patch.", $targetId)
                        );
                        break;
                    }

                    if (!array_key_exists('after', $op)) {
                        $problems[] = new Problem("{$opPath}.after", 'invalid_value', "Move operation requires 'after' (section ref or null).");
                        break;
                    }

                    $afterRef = $op['after'];
                    $afterId = null;
                    if ($afterRef !== null) {
                        $afterId = $this->resolveExistingRootId($afterRef, $currentRoots);
                        if ($afterId === null) {
                            $problems[] = new Problem(
                                "{$opPath}.after",
                                'invalid_ref',
                                sprintf("Target 'after' ref '%s' does not exist.", is_scalar($afterRef) ? (string) $afterRef : gettype($afterRef))
                            );
                            break;
                        }
                        if ($afterId === $targetId) {
                            $problems[] = new Problem(
                                "{$opPath}.after",
                                'invalid_ref',
                                sprintf("Section 's_%s' cannot be moved after itself.", $targetId)
                            );
                            break;
                        }
                    }

                    $movedRefs[$targetId] = $idx;
                    $withoutTarget = array_values(array_filter(
                        $currentRoots,
                        static fn(string $r): bool => $r !== $targetId
                    ));
                    $currentRoots = $this->insertRootId($withoutTarget, $targetId, $afterId);
                    break;

                case 'remove':
                    $ref = $op['ref'] ?? null;
                    $targetId = $this->resolveExistingRootId($ref, $currentRoots);
                    if ($targetId === null) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Target section ref '%s' does not exist.", is_scalar($ref) ? (string) $ref : gettype($ref))
                        );
                        break;
                    }

                    if (isset($removedRefs[$targetId]) || isset($replacedRefs[$targetId]) || isset($movedRefs[$targetId])) {
                        $problems[] = new Problem(
                            "{$opPath}.ref",
                            'invalid_ref',
                            sprintf("Section ref 's_%s' cannot be removed when already targeted by another operation in the same patch.", $targetId)
                        );
                        break;
                    }

                    $removedRefs[$targetId] = $idx;
                    $currentRoots = array_values(array_filter(
                        $currentRoots,
                        static fn(string $r): bool => $r !== $targetId
                    ));
                    unset($currentSubtrees[$targetId]);
                    break;
            }
        }

        foreach ($problems as $problem) {
            if ($problem->isError()) {
                return new ConversionResult([], $problems);
            }
        }

        $resultElements = [];
        foreach ($currentRoots as $rootId) {
            if (!isset($currentSubtrees[$rootId])) {
                continue;
            }
            foreach ($currentSubtrees[$rootId] as $el) {
                $resultElements[] = $el;
            }
        }

        if (count($resultElements) > $vocab->maxElements() && count($resultElements) > count($storedElements)) {
            $problems[] = new Problem(
                'ops',
                'limit_exceeded',
                sprintf(
                    'Resulting document has %d elements, exceeding the maximum allowed (%d).',
                    count($resultElements),
                    $vocab->maxElements()
                )
            );
            return new ConversionResult([], $problems);
        }

        return new ConversionResult($resultElements, $problems);
    }

    /**
     * @param list<string> $currentRoots
     */
    private function resolveExistingRootId(mixed $ref, array $currentRoots): ?string
    {
        if (!is_string($ref) || !str_starts_with($ref, 's_') || strlen($ref) <= 2) {
            return null;
        }
        $id = substr($ref, 2);
        return in_array($id, $currentRoots, true) ? $id : null;
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function insertRootId(array $roots, string $newId, ?string $afterId): array
    {
        if ($afterId === null) {
            array_unshift($roots, $newId);
            return array_values($roots);
        }

        $out = [];
        $inserted = false;
        foreach ($roots as $r) {
            $out[] = $r;
            if ($r === $afterId && !$inserted) {
                $out[] = $newId;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $out[] = $newId;
        }

        return $out;
    }

    private function rewriteSectionProblemPath(Problem $p, string $sectionPrefix): Problem
    {
        $path = $p->path();
        if ($path === 'sections[0]') {
            $newPath = $sectionPrefix;
        } elseif (str_starts_with($path, 'sections[0].')) {
            $newPath = $sectionPrefix . '.' . substr($path, strlen('sections[0].'));
        } elseif ($path === '') {
            $newPath = $sectionPrefix;
        } else {
            $newPath = $sectionPrefix . '.' . $path;
        }

        return new Problem(
            $newPath,
            $p->code(),
            $p->message(),
            $p->hint(),
            $p->severity()
        );
    }
}
