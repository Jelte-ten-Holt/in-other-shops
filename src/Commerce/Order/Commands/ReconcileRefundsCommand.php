<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Payment\Models\Payment;

/**
 * Read-only refund tripwire. Reports — never repairs — every payment on an
 * order whose `amount_refunded` differs from the sum of its Refund rows, and
 * exits non-zero when any does, so a scheduled run surfaces it to monitoring.
 *
 * The payment webhook brings the rows in line with the gateway only when a
 * refund event MOVES the payment total (ReconcileRefundFromWebhook). Three
 * states sit outside that trigger and nothing else finds them: a refund made
 * outside the app before that reconciliation existed, an admin refund whose
 * row write failed after the money moved, and a later event of a run of
 * refunds that never arrived.
 *
 * Payments touched in the last fifteen minutes are skipped: between two events
 * of one run the rows are ahead of the total by design, and the next event
 * closes the gap.
 *
 * Not auto-scheduled: consumers wire it into their own scheduler + alerting.
 */
final class ReconcileRefundsCommand extends Command
{
    private const int SETTLING_MINUTES = 15;

    protected $signature = 'commerce:reconcile-refunds';

    protected $description = 'Report order payments whose refunded total differs from their recorded refunds (read-only; exits non-zero on a mismatch)';

    public function handle(): int
    {
        $mismatches = $this->mismatches();

        if ($mismatches->isEmpty()) {
            $this->info('Refunds reconciled clean: every order payment\'s refunded total matches its recorded refunds.');

            return self::SUCCESS;
        }

        $this->error($mismatches->count().' payment(s) have a refunded total that differs from their recorded refunds:');
        $this->table(
            ['payment', 'order', 'gateway_reference', 'refunded', 'recorded', 'delta'],
            $mismatches->map(fn (Payment $payment): array => [
                $payment->getKey(),
                $payment->payable?->order_number,
                $payment->gateway_reference,
                $payment->amount_refunded,
                (int) $payment->recorded_refunds,
                $payment->amount_refunded - (int) $payment->recorded_refunds,
            ])->all(),
        );

        Log::warning('Refund reconciliation found payments that disagree with their refund rows', [
            'payment_ids' => $mismatches->modelKeys(),
        ]);

        return self::FAILURE;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function mismatches(): Collection
    {
        // Shops are small by design — one pass with the rows summed in SQL is
        // fine; no chunking needed at this scale.
        return Payment::query()
            ->where('payable_type', (new (Commerce::order()))->getMorphClass())
            ->where('updated_at', '<', now()->subMinutes(self::SETTLING_MINUTES))
            ->addSelect(['recorded_refunds' => Commerce::refund()::query()
                ->selectRaw('coalesce(sum(amount), 0)')
                ->whereColumn('refunds.payment_id', 'payments.id'),
            ])
            ->with('payable')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $payment): bool => $payment->amount_refunded !== (int) $payment->recorded_refunds)
            ->values();
    }
}
