<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support\Filament;

use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use InOtherShops\Support\Filament\ClaimedDay;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A claimed day is stored as the start of that day in the admin's zone and
 * shown as the same day again, winter and summer. These are the transforms the
 * picker runs; the picker's wiring itself is not rendered here.
 */
final class ClaimedDayTest extends TestCase
{
    protected function tearDown(): void
    {
        FilamentTimezone::set(null);

        parent::tearDown();
    }

    #[Test]
    public function a_day_is_the_start_of_that_day_in_the_admins_zone_winter_and_summer(): void
    {
        FilamentTimezone::set('Europe/Berlin');

        $this->assertSame('2026-11-11T23:00:00+00:00', ClaimedDay::startOf('2026-11-12')?->toIso8601String());
        $this->assertSame('2026-06-30T22:00:00+00:00', ClaimedDay::startOf('2026-07-01')?->toIso8601String());
    }

    #[Test]
    public function the_stored_start_of_a_day_shows_as_that_day_again(): void
    {
        FilamentTimezone::set('Europe/Berlin');

        $this->assertSame('2026-11-12', ClaimedDay::dayOf(CarbonImmutable::parse('2026-11-11 23:00:00', 'UTC')));
        // As Filament fills it from a model: the serialised UTC string.
        $this->assertSame('2026-11-12', ClaimedDay::dayOf('2026-11-11T23:00:00.000000Z'));
        $this->assertSame('2026-07-01', ClaimedDay::dayOf(ClaimedDay::startOf('2026-07-01')));
    }

    #[Test]
    public function without_a_pinned_zone_the_day_starts_at_midnight_in_the_app_zone(): void
    {
        $this->assertSame('2026-11-12T00:00:00+00:00', ClaimedDay::startOf('2026-11-12')?->toIso8601String());
    }

    #[Test]
    public function an_empty_day_stays_empty_both_ways(): void
    {
        $this->assertNull(ClaimedDay::startOf(null));
        $this->assertNull(ClaimedDay::startOf(''));
        $this->assertNull(ClaimedDay::dayOf(null));
    }
}
