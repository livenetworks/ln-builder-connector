<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class ConversionResult
{
    /** @var array<string, mixed>|array<int, array<string, mixed>> */
    private array $data;

    /** @var Problem[] */
    private array $problems;

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $data
     * @param Problem[] $problems
     */
    public function __construct(array $data, array $problems = [])
    {
        $this->data = $data;
        $this->problems = $problems;
    }

    /**
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return Problem[]
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return Problem[]
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->problems,
            static fn(Problem $p): bool => $p->isError()
        ));
    }

    /**
     * @return Problem[]
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->problems,
            static fn(Problem $p): bool => !$p->isError()
        ));
    }

    public function hasErrors(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->isError()) {
                return true;
            }
        }
        return false;
    }
}
