<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Commands;

use InOtherShops\Commerce\Commerce;
use Illuminate\Console\Command;

final class PruneExpiredCartsCommand extends Command
{
    protected $signature = 'commerce:prune-carts';

    protected $description = 'Delete guest carts whose expires_at is in the past';

    public function handle(): int
    {
        $deleted = Commerce::cart()::query()
            ->whereNull('owner_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Deleted {$deleted} expired guest cart(s).");

        return self::SUCCESS;
    }
}
