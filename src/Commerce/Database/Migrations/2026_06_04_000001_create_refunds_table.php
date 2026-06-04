<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Soft cross-domain reference to the Payment the money moved through.
            // No DB foreign key — Commerce depends on Payment at the type level,
            // but the table stays decoupled (Payment is independently extractable).
            $table->unsignedBigInteger('payment_id')->nullable()->index();

            $table->string('gateway');
            $table->string('gateway_refund_id')->nullable();

            $table->integer('amount'); // gross cents returned by this refund

            // Reversed VAT for this refund, per rate bracket (the delta this
            // refund contributes): list of {rate_bps, taxable_base, tax}.
            $table->json('tax_summary')->nullable();

            $table->string('reason')->nullable();

            // Who issued it — recorded, never a silent null (see RefundActor).
            $table->string('actor_source');
            $table->string('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            $table->timestamps();

            // The idempotency anchor: an admin refund and its echoing gateway
            // webhook converge on one row instead of double-counting.
            $table->unique(['gateway', 'gateway_refund_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
