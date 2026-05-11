<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_morph_counts', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('morph_alias', 64);
            $table->unsignedInteger('count')->default(0);

            $table->primary(['category_id', 'morph_alias']);
            $table->index(['morph_alias', 'count']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('category_morph_counts');
    }

    private function backfill(): void
    {
        $directCounts = DB::table('categorizables')
            ->selectRaw('category_id, categorizable_type AS morph_alias, COUNT(*) AS count')
            ->groupBy('category_id', 'categorizable_type')
            ->get();

        if ($directCounts->isEmpty()) {
            return;
        }

        $parents = DB::table('categories')
            ->pluck('parent_id', 'id')
            ->all();

        $aggregated = [];

        foreach ($directCounts as $row) {
            $categoryId = (int) $row->category_id;
            $alias = (string) $row->morph_alias;
            $count = (int) $row->count;

            $current = $categoryId;
            while ($current !== null) {
                $aggregated[$current][$alias] = ($aggregated[$current][$alias] ?? 0) + $count;
                $current = $parents[$current] ?? null;
                $current = $current === null ? null : (int) $current;
            }
        }

        $rows = [];

        foreach ($aggregated as $categoryId => $byAlias) {
            foreach ($byAlias as $alias => $count) {
                $rows[] = [
                    'category_id' => $categoryId,
                    'morph_alias' => $alias,
                    'count' => $count,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('category_morph_counts')->insert($rows);
        }
    }
};
