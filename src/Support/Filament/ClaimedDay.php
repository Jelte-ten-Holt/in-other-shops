<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Facades\FilamentTimezone;

/**
 * A day a person says something happened on — a supplier invoice paid, a bank
 * transfer received — stored as a timestamp: the start of that day in the zone
 * the admin shows (Filament's timezone, which a consumer pins to the shop's
 * operating zone). The bookkeeping app reads the timestamp and dates it in the
 * tenant's own zone, so the day survives the trip.
 *
 * Filament's date-only picker works in `app.timezone` and would show a Berlin
 * midnight stored as 23:00 UTC as the day before; both directions are
 * converted here instead.
 */
final class ClaimedDay
{
    public static function picker(string $name): DatePicker
    {
        return DatePicker::make($name)
            ->formatStateUsing(fn (mixed $state): ?string => self::dayOf($state))
            ->dehydrateStateUsing(fn (mixed $state): ?CarbonImmutable => self::startOf($state));
    }

    /** `Y-m-d` in the admin's zone, or null. */
    public static function startOf(mixed $day): ?CarbonImmutable
    {
        if ($day === null || $day === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $day, FilamentTimezone::get())->startOfDay()->utc();
    }

    /** The day a stored timestamp falls on in the admin's zone, as `Y-m-d`. */
    public static function dayOf(mixed $instant): ?string
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        $at = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse((string) $instant, (string) config('app.timezone'));

        return $at->setTimezone(FilamentTimezone::get())->toDateString();
    }
}
