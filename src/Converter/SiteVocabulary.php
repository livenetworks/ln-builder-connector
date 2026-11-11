<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class SiteVocabulary
{
    private string $builder;
    private string $siteId;
    private string $extraSecret;
    private bool $extraEnabled;
    private string $tokenDiscipline;
    private int $maxDepth;
    private int $maxElements;

    /** @var array<string, string> class name => builder class ID */
    private array $classesByName = [];

    /** @var array<string, string> builder class ID => class name */
    private array $classesById = [];

    /** @var array<string, true> normalized variable name (with --) => true */
    private array $variables = [];

    /** @var array<string, string> color name => css_var */
    private array $colors = [];

    /** @var string[] */
    private array $allowedAreas;

    /** @var array<string, array<string, mixed>> native element name => info */
    private array $registeredElements;

    /**
     * @param array<int, array{id: string, name: string}> $classes
     * @param array<int, array{id?: string, name: string}> $variables
     * @param array<int, array{id?: string, name: string, css_var?: string}> $colors
     * @param string[] $allowedAreas
     * @param array<string, array<string, mixed>> $registeredElements
     */
    public function __construct(
        string $builder,
        string $siteId,
        string $extraSecret,
        array $classes = [],
        array $variables = [],
        array $colors = [],
        array $allowedAreas = ['content', 'header', 'footer'],
        array $registeredElements = [],
        bool $extraEnabled = true,
        string $tokenDiscipline = Problem::SEVERITY_ERROR,
        int $maxDepth = 12,
        int $maxElements = 2000
    ) {
        $this->builder = $builder;
        $this->siteId = $siteId;
        $this->extraSecret = $extraSecret;
        $this->extraEnabled = $extraEnabled;
        $this->tokenDiscipline = $tokenDiscipline === Problem::SEVERITY_WARNING
            ? Problem::SEVERITY_WARNING
            : Problem::SEVERITY_ERROR;
        $this->maxDepth = $maxDepth;
        $this->maxElements = $maxElements;
        $this->allowedAreas = $allowedAreas;
        $this->registeredElements = $registeredElements;

        foreach ($classes as $cls) {
            $id = (string) ($cls['id'] ?? '');
            $name = (string) ($cls['name'] ?? '');
            if ($id !== '' && $name !== '') {
                $this->classesByName[$name] = $id;
                $this->classesById[$id] = $name;
            }
        }

        foreach ($variables as $var) {
            $name = (string) ($var['name'] ?? '');
            if ($name !== '') {
                $normalized = str_starts_with($name, '--') ? $name : '--' . $name;
                $this->variables[$normalized] = true;
            }
        }

        foreach ($colors as $color) {
            $name = (string) ($color['name'] ?? '');
            $cssVar = (string) ($color['css_var'] ?? '');
            if ($name !== '') {
                $this->colors[$name] = $cssVar !== '' ? $cssVar : '--' . ltrim($name, '-');
            }
            if ($cssVar !== '') {
                $normalized = str_starts_with($cssVar, '--') ? $cssVar : '--' . $cssVar;
                $this->variables[$normalized] = true;
            }
        }
    }

    public function builder(): string
    {
        return $this->builder;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function extraSecret(): string
    {
        return $this->extraSecret;
    }

    public function extraEnabled(): bool
    {
        return $this->extraEnabled;
    }

    public function tokenDiscipline(): string
    {
        return $this->tokenDiscipline;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function maxElements(): int
    {
        return $this->maxElements;
    }

    /**
     * @return array<string, string>
     */
    public function classesByName(): array
    {
        return $this->classesByName;
    }

    /**
     * @return array<string, string>
     */
    public function classesById(): array
    {
        return $this->classesById;
    }

    /**
     * @return string[]
     */
    public function variableNames(): array
    {
        return array_keys($this->variables);
    }

    public function hasVariable(string $name): bool
    {
        $normalized = str_starts_with($name, '--') ? $name : '--' . $name;
        return isset($this->variables[$normalized]);
    }

    /**
     * @return string[]
     */
    public function allowedAreas(): array
    {
        return $this->allowedAreas;
    }

    public function isNativeElementRegistered(string $nativeName): bool
    {
        if ($this->registeredElements === []) {
            return true;
        }
        return isset($this->registeredElements[$nativeName]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function registeredElements(): array
    {
        return $this->registeredElements;
    }
}
