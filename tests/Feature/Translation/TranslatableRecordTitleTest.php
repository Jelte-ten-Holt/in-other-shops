<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Translation;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A translatable model must keep Eloquent's null-key contract.
 *
 * `HasAttributes::getAttribute()` opens with `if (! $key) { return; }`, and
 * Filament leans on it: `Resource::getRecordTitle()` hands
 * `static::$recordTitleAttribute` straight to the model and falls back to the
 * model label when null comes back. Most resources never set that property, so
 * the key IS null on the ordinary path.
 *
 * {@see InteractsWithTranslations} overrides `getAttribute()` and used to
 * forward the key into a `string`-typed check before deferring to the parent,
 * which turned that null into a TypeError. `DeleteAction` asks for the record
 * title to build its modal heading, so deleting a category — or any other
 * translatable record — fatalled before the confirmation modal ever opened.
 */
final class TranslatableRecordTitleTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    #[Test]
    public function a_translatable_model_returns_null_for_a_null_attribute_key(): void
    {
        $category = Category::factory()->create();

        $this->assertNull($category->getAttribute(null));
    }

    #[Test]
    public function the_delete_modal_heading_resolves_for_a_translatable_record(): void
    {
        $category = Category::factory()->create();

        // The exact call DeleteAction makes for its modal heading. It threw a
        // TypeError here, so the delete action was unreachable in the admin.
        $this->assertSame(
            CategoryResource::getModelLabel(),
            CategoryResource::getRecordTitle($category),
        );
    }

    #[Test]
    public function every_shipped_resource_on_a_translatable_model_resolves_a_record_title(): void
    {
        $translatable = array_filter(
            $this->shippedResources(),
            fn (string $resource): bool => in_array(
                InteractsWithTranslations::class,
                class_uses_recursive($resource::getModel()),
                true,
            ),
        );

        // Guard the guard: Category, Tag and Option are all translatable today,
        // so an empty census means the sweep stopped finding resources.
        $this->assertGreaterThanOrEqual(3, count($translatable));

        foreach ($translatable as $resource) {
            $model = $resource::getModel();

            $this->assertNotNull(
                $resource::getRecordTitle(new $model),
                "{$resource} must resolve a record title; DeleteAction and every other modal heading depend on it.",
            );
        }
    }

    /** @return list<class-string<\Filament\Resources\Resource>> */
    private function shippedResources(): array
    {
        $src = dirname(__DIR__, 3).'/src';
        $resources = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, 'Resource.php') || ! str_contains($path, '/Filament/Resources/')) {
                continue;
            }

            $relative = substr($path, strlen($src) + 1, -4); // strip src/ and .php
            $resource = 'InOtherShops\\'.str_replace('/', '\\', $relative);

            if (is_subclass_of($resource, Model::class)) {
                continue;
            }

            $resources[] = $resource;
        }

        return $resources;
    }
}
