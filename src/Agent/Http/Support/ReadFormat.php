<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;

/**
 * The read API's value formats, one place: timestamps in UTC with a `Z`,
 * dates as `Y-m-d`, enums as their value, per-rate summaries as
 * `{rate_bps, taxable_base, tax}` rows. The bookkeeping app's fixtures pin
 * them; a change here is a change to its contract.
 */
final class ReadFormat
{
    public const string TIMESTAMP = 'Y-m-d\TH:i:s\Z';

    public static function at(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format(self::TIMESTAMP);
    }

    public static function day(?DateTimeInterface $day): ?string
    {
        return $day?->format('Y-m-d');
    }

    public static function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * @param  list<TaxBreakdownLine>  $lines
     * @return list<array{rate_bps: int, taxable_base: int, tax: int}>
     */
    public static function summary(array $lines): array
    {
        return TaxBreakdownLine::serializeMany($lines);
    }
}
