<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Builders;

final class Capabilities
{
    private string $globalClasses;
    private string $variables;
    private string $globalColors;
    private string $globalFonts;
    /** @var string[] */
    private array $areas;
    /** @var string[] */
    private array $templateTypes;
    private string $displayConditions;

    /**
     * @param string[] $areas
     * @param string[] $templateTypes
     */
    public function __construct(
        string $globalClasses = 'native',
        string $variables = 'native',
        string $globalColors = 'native',
        string $globalFonts = 'native',
        array $areas = ['content', 'header', 'footer'],
        array $templateTypes = ['section', 'content', 'header', 'footer', 'popup', 'archive', 'search', 'error'],
        string $displayConditions = 'read'
    ) {
        $this->globalClasses = $globalClasses;
        $this->variables = $variables;
        $this->globalColors = $globalColors;
        $this->globalFonts = $globalFonts;
        $this->areas = $areas;
        $this->templateTypes = $templateTypes;
        $this->displayConditions = $displayConditions;
    }

    /**
     * @return string[]
     */
    public function areas(): array
    {
        return $this->areas;
    }

    /**
     * @return string[]
     */
    public function templateTypes(): array
    {
        return $this->templateTypes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'global_classes' => $this->globalClasses,
            'variables' => $this->variables,
            'global_colors' => $this->globalColors,
            'global_fonts' => $this->globalFonts,
            'areas' => $this->areas,
            'template_types' => $this->templateTypes,
            'display_conditions' => $this->displayConditions,
        ];
    }
}
