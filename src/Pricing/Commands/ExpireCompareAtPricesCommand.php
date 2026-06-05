<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Commands;

use Illuminate\Console\Command;
use InOtherShops\Logging\Concerns\RunsAsSystemActor;
use InOtherShops\Pricing\Actions\ExpireCompareAtPrices;

final class ExpireCompareAtPricesCommand extends Command
{
    use RunsAsSystemActor;

    protected $signature = 'pricing:expire-compare-at';

    protected $description = 'Promote prices whose strikethrough window has closed to their compare-at amount';

    public function handle(ExpireCompareAtPrices $action): int
    {
        $this->beginSystemAuditActor();

        $expired = $action();

        $this->info("Expired strikethrough on {$expired->count()} price(s).");

        return self::SUCCESS;
    }
}
