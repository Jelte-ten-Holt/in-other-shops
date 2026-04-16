<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('session_token')->nullable()->unique();
            $table->nullableMorphs('owner');
            $table->string('currency', 3);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // At most one cart per authenticated owner. MySQL treats NULLs as
            // distinct in unique indexes, so guest carts (owner_type/id NULL)
            // are unaffected and remain keyed by session_token alone.
            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
