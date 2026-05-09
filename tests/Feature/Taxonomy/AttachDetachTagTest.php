<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Taxonomy\Actions\AttachTag;
use InOtherShops\Taxonomy\Actions\DetachTag;
use InOtherShops\Taxonomy\Events\TagAttached;
use InOtherShops\Taxonomy\Events\TagDetached;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\Stubs\TestTaxonomized;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AttachDetachTagTest extends TestCase
{
    use RefreshDatabase;

    private AttachTag $attach;

    private DetachTag $detach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attach = new AttachTag;
        $this->detach = new DetachTag;
    }

    #[Test]
    public function it_writes_a_pivot_row_when_attaching_a_tag(): void
    {
        $model = TestTaxonomized::factory()->create();
        $tag = Tag::factory()->create();

        ($this->attach)($model, $tag);

        $this->assertSame(1, $model->tags()->count());
        $this->assertTrue($model->tags()->where('tags.id', $tag->id)->exists());
    }

    #[Test]
    public function it_dispatches_tag_attached_with_the_model_and_tag(): void
    {
        Event::fake([TagAttached::class]);

        $model = TestTaxonomized::factory()->create();
        $tag = Tag::factory()->create();

        ($this->attach)($model, $tag);

        Event::assertDispatched(
            TagAttached::class,
            fn (TagAttached $event) => $event->model->is($model)
                && $event->tag->is($tag),
        );
    }

    #[Test]
    public function attaching_the_same_tag_twice_violates_the_unique_pivot_constraint(): void
    {
        $model = TestTaxonomized::factory()->create();
        $tag = Tag::factory()->create();

        ($this->attach)($model, $tag);

        $this->expectException(QueryException::class);

        ($this->attach)($model, $tag);
    }

    #[Test]
    public function it_supports_attaching_multiple_distinct_tags_including_typed_ones(): void
    {
        // Per CLAUDE.md / Taxonomy docs, tags are typed-flat — multiple types
        // co-exist on one model (e.g. a regular tag plus a 'hidden_on_front'
        // tag). Verify the unique constraint is per-tag, not per-type.
        $model = TestTaxonomized::factory()->create();
        $regular = Tag::factory()->create();
        $hidden = Tag::factory()->hidden()->create();

        ($this->attach)($model, $regular);
        ($this->attach)($model, $hidden);

        $this->assertSame(2, $model->tags()->count());
    }

    #[Test]
    public function it_removes_the_pivot_row_when_detaching_a_tag(): void
    {
        $model = TestTaxonomized::factory()->create();
        $tag = Tag::factory()->create();

        ($this->attach)($model, $tag);
        ($this->detach)($model, $tag);

        $this->assertSame(0, $model->tags()->count());
    }

    #[Test]
    public function it_dispatches_tag_detached_with_the_model_and_tag(): void
    {
        $model = TestTaxonomized::factory()->create();
        $tag = Tag::factory()->create();
        ($this->attach)($model, $tag);

        Event::fake([TagDetached::class]);

        ($this->detach)($model, $tag);

        Event::assertDispatched(
            TagDetached::class,
            fn (TagDetached $event) => $event->model->is($model)
                && $event->tag->is($tag),
        );
    }

    #[Test]
    public function detaching_one_tag_leaves_the_others_attached(): void
    {
        $model = TestTaxonomized::factory()->create();
        $keep = Tag::factory()->create();
        $remove = Tag::factory()->create();

        ($this->attach)($model, $keep);
        ($this->attach)($model, $remove);

        ($this->detach)($model, $remove);

        $remaining = $model->tags()->pluck('tags.id')->all();
        $this->assertSame([$keep->id], $remaining);
    }
}
