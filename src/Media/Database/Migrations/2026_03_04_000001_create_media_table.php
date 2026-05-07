<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->string('alt')->nullable();
            $table->string('type')->default('upload');
            $table->string('url')->nullable();
            $table->timestamps();
        });

        Schema::create('mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('collection');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            $table->unique(['media_id', 'mediable_type', 'mediable_id', 'collection'], 'mediables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
        Schema::dropIfExists('media');
    }
};
