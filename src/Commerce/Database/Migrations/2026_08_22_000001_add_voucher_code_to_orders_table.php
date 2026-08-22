<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which voucher produced `orders.discount`. The order already stored the
     * amount but not its cause, so a discounted order was untraceable to the
     * campaign that caused it and per-code reporting was impossible.
     *
     * A code snapshot, not a `voucher_id` FK: the order is the record of a
     * transaction and has to stay true after the voucher row is edited or
     * deleted — the same reason tax and shipping are snapshotted here rather
     * than referenced. Indexed because the query this exists for is "every
     * order that used code X".
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('voucher_code')->nullable()->after('discount');
            $table->index('voucher_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['voucher_code']);
            $table->dropColumn('voucher_code');
        });
    }
};
