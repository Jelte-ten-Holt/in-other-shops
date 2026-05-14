<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Commands;

use Illuminate\Console\Command;
use InOtherShops\Pricing\Actions\ExpireCompareAtPrices;

final class ExpireCompareAtPricesCommand extends Command
{
    protected $signature = 'pricing:expire-compare-at';

    protected $description = 'Promote prices whose strikethrough window has closed to their compare-at amount';

    public function handle(ExpireCompareAtPrices $action): int
    {
        $expired = $action();

        $this->info("Expired strikethrough on {$expired->count()} price(s).");

        return self::SUCCESS;
    }
}
