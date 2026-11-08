<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Exceptions;

/**
 * Thrown by the migration that drops the variant tables while a cart line or
 * an order line still points at a `variant`. The `variant` morph alias is gone
 * once that migration has run, and the package requires a morph map, so such a
 * line could no longer load: the migration stops rather than strand it.
 */
final class VariantStillReferencedException extends VariantsException
{
    /**
     * @param  array<string, int>  $counts  table => lines still pointing at a variant
     */
    public static function by(array $counts): self
    {
        $lines = implode(', ', array_map(
            fn (string $table, int $count): string => "{$count} in {$table}",
            array_keys($counts),
            $counts,
        ));

        return new self("Cannot drop the variant tables: lines still point at a variant ({$lines}). Remove or re-point them first.");
    }
}
