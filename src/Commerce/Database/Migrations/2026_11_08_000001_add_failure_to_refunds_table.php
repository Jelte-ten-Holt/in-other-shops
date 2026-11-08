<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refund the gateway accepted can fail later (R4: a closed or expired
     * card). The row stays, as the record that the refund was made and given up
     * on; `failed_at` takes it out of the order's refunded total and its VAT
     * reversal. One ALTER, so MySQL applies both columns or neither.
     */
    public function up(): void
    {
        if (Schema::hasColumn('refunds', 'failed_at')) {
            return;
        }

        Schema::table('refunds', function (Blueprint $table): void {
            $table->timestamp('failed_at')->nullable()->after('reason');
            $table->string('failure_reason')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn(['failed_at', 'failure_reason']);
        });
    }
};
