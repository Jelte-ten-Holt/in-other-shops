<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Commands;

use InOtherShops\Inventory\Actions\ReleaseExpiredReservations;
use Illuminate\Console\Command;

final class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'inventory:release-expired';

    protected $description = 'Release stock reservations that have expired';

    public function handle(ReleaseExpiredReservations $action): int
    {
        $released = $action();

        $this->info("Released {$released->count()} expired reservation(s).");

        return self::SUCCESS;
    }
}
