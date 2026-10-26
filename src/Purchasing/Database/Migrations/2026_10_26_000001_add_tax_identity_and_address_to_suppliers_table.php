<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who issued a supplier invoice, as the books need it (bookkeeping brief
     * §12 P-4): the VAT-ID and the country decide the purchase's VAT treatment
     * (domestic, intra-community, reverse charge), and the address is part of
     * the invoice. Plain columns, not a Location address: those carry a
     * person's first and last name, which a supplier company does not have.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('vat_id', 32)->nullable()->after('payment_terms');
            $table->string('country_code', 2)->nullable()->after('vat_id');
            $table->string('address_line_1')->nullable()->after('country_code');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('postal_code', 20)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['vat_id', 'country_code', 'address_line_1', 'address_line_2', 'city', 'postal_code']);
        });
    }
};
