<?php

declare(strict_types=1);

namespace InOtherShops\Agent\DTOs;

final readonly class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $redactedInput
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public string $tool,
        public array $redactedInput,
        public ?array $output,
        public ?string $error,
        public float $durationMs,
        public ?string $bearerHash,
    ) {}
}
