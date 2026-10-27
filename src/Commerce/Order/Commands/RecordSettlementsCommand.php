<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Commands;

use Illuminate\Console\Command;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Actions\RecordRefundSettlement;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Payment\Actions\RecordPaymentSettlement;
use InOtherShops\Payment\Contracts\ReportsSettlements;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use Throwable;

/**
 * Records what each settled payment and refund did to the shop's balance at
 * its gateway (bookkeeping brief §12 P-2), once. A gateway knows this some
 * time after the payment succeeds — Stripe's asynchronous capture leaves the
 * balance transaction empty on `payment_intent.succeeded` and fills it within
 * the hour — so the package asks on its own schedule instead of at the
 * webhook, which also heals a delivery that never came.
 *
 * Asks only gateways that implement ReportsSettlements, only about rows from
 * the last 30 days that have no settlement yet, and never inside a
 * transaction. A gateway error on one row is reported and the run moves on;
 * the row is asked again next run.
 */
final class RecordSettlementsCommand extends Command
{
    protected $signature = 'commerce:record-settlements';

    protected $description = 'Record gateway settlements for settled payments and refunds that have none yet';

    private const int LOOKBACK_DAYS = 30;

    public function handle(
        PaymentGatewayManager $gateways,
        RecordPaymentSettlement $recordPayment,
        RecordRefundSettlement $recordRefund,
    ): int {
        $settling = $this->settlingGateways($gateways);

        if ($settling === []) {
            $this->info('No registered gateway reports settlements.');

            return self::SUCCESS;
        }

        $recorded = 0;
        $failed = 0;

        $payments = Payment::query()
            ->whereIn('gateway', array_keys($settling))
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])
            ->whereNull('settlement_reference')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS));

        foreach ($payments->lazyById() as $payment) {
            try {
                $settlement = $settling[$payment->gateway]->settlementFor($payment);
            } catch (Throwable $e) {
                report($e);
                $failed++;

                continue;
            }

            if ($settlement !== null && $recordPayment($payment, $settlement)) {
                $recorded++;
            }
        }

        $refunds = Commerce::refund()::query()
            ->whereIn('gateway', array_keys($settling))
            ->whereNotNull('gateway_refund_id')
            ->whereNull('settlement_reference')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS));

        /** @var Refund $refund */
        foreach ($refunds->lazyById() as $refund) {
            try {
                $settlement = $settling[$refund->gateway]->refundSettlementFor($refund->gateway_refund_id);
            } catch (Throwable $e) {
                report($e);
                $failed++;

                continue;
            }

            if ($settlement !== null && $recordRefund($refund, $settlement)) {
                $recorded++;
            }
        }

        $this->info("Recorded {$recorded} settlement(s); {$failed} gateway error(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, ReportsSettlements> */
    private function settlingGateways(PaymentGatewayManager $gateways): array
    {
        $settling = [];

        foreach ($gateways->registered() as $name) {
            $gateway = $gateways->gateway($name);

            if ($gateway instanceof ReportsSettlements) {
                $settling[$name] = $gateway;
            }
        }

        return $settling;
    }
}
