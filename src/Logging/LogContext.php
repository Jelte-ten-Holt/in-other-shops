<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

final class LogContext
{
    /** @var array<string, mixed> */
    private array $context = [];

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->context;
    }
}
