<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Auth;

final class RequestContext
{
    private static ?self $current = null;

    private string $requestId;
    private string $kid;
    private string $iss;
    private string $actor;
    private string $session;
    /** @var string[] */
    private array $scopes;
    private bool $isDevAuth;
    private ?string $actorName;
    private ?string $org;
    /** @var list<array<string, mixed>> */
    private array $warnings;

    /**
     * @param string[] $scopes
     * @param list<array<string, mixed>> $warnings
     */
    public function __construct(
        string $requestId,
        string $kid,
        string $iss,
        string $actor,
        string $session,
        array $scopes,
        bool $isDevAuth = false,
        ?string $actorName = null,
        ?string $org = null,
        array $warnings = []
    ) {
        $this->requestId = $requestId;
        $this->kid = $kid;
        $this->iss = $iss;
        $this->actor = $actor;
        $this->session = $session;
        $this->scopes = $scopes;
        $this->isDevAuth = $isDevAuth;
        $this->actorName = $actorName;
        $this->org = $org;
        $this->warnings = $warnings;
    }

    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function currentRequestId(): string
    {
        if (self::$current !== null) {
            return self::$current->requestId();
        }
        static $fallbackId = null;
        if ($fallbackId === null) {
            $fallbackId = 'req_' . substr(bin2hex(random_bytes(8)), 0, 12);
        }
        return $fallbackId;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function kid(): string
    {
        return $this->kid;
    }

    public function iss(): string
    {
        return $this->iss;
    }

    public function actor(): string
    {
        return $this->actor;
    }

    public function session(): string
    {
        return $this->session;
    }

    /**
     * @return string[]
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isDevAuth(): bool
    {
        return $this->isDevAuth;
    }

    public function actorName(): ?string
    {
        return $this->actorName;
    }

    public function org(): ?string
    {
        return $this->org;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
