<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Problem
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    private string $path;
    private string $code;
    private string $message;
    private ?string $hint;
    private string $severity;

    public function __construct(
        string $path,
        string $code,
        string $message,
        ?string $hint = null,
        string $severity = self::SEVERITY_ERROR
    ) {
        $this->path = $path;
        $this->code = $code;
        $this->message = $message;
        $this->hint = $hint;
        $this->severity = $severity;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [
            'path' => $this->path,
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->hint !== null && $this->hint !== '') {
            $out['hint'] = $this->hint;
        }
        return $out;
    }
}
