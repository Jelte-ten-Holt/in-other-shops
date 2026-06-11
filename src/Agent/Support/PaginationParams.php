<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

/**
 * Clamped pagination arguments for list tools. Limits stay per-tool — each
 * caller passes its own max/default — only the clamping mechanics are shared.
 */
final readonly class PaginationParams
{
    private function __construct(
        public int $page,
        public int $perPage,
    ) {}

    /** @param array<string, mixed> $arguments */
    public static function fromArguments(array $arguments, int $maxPerPage, int $defaultPerPage): self
    {
        return new self(
            page: max(1, (int) ($arguments['page'] ?? 1)),
            perPage: min($maxPerPage, max(1, (int) ($arguments['per_page'] ?? $defaultPerPage))),
        );
    }
}
