<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 20);
            $table->string('channel', 100);
            $table->text('message');
            $table->json('context');
            $table->timestamp('created_at')->nullable();

            $table->index('level');
            $table->index('channel');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_logs');
    }
};
