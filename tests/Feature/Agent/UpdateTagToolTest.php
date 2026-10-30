<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Agent\Tools\UpdateTag;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class UpdateTagToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
        config(['translation.locales' => ['en', 'es']]);
    }

    #[Test]
    public function it_updates_only_the_locales_given(): void
    {
        $tag = $this->tag();

        $result = app(UpdateTag::class)(['slug' => 'horror', 'name' => ['es' => 'Horror gótico']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['en' => 'Horror', 'es' => 'Horror gótico'], $result['data']['names']);
        $this->assertSame('Horror', $tag->fresh()->translated('name', 'en'));
    }

    #[Test]
    public function fields_left_out_of_the_call_are_left_alone(): void
    {
        $tag = $this->tag(['type' => 'genre', 'position' => 5, 'is_active' => false]);

        app(UpdateTag::class)(['slug' => 'horror', 'name' => ['en' => 'Horror!']]);

        $tag->refresh();
        $this->assertSame('genre', $tag->type);
        $this->assertSame(5, $tag->position);
        $this->assertFalse($tag->is_active);
        $this->assertSame('Terror', $tag->translated('name', 'es'));
    }

    #[Test]
    public function it_changes_type_position_and_active_flag(): void
    {
        $tag = $this->tag(['type' => 'genre']);

        app(UpdateTag::class)(['slug' => 'horror', 'type' => null, 'position' => 2, 'is_active' => false]);

        $tag->refresh();
        $this->assertNull($tag->type);
        $this->assertSame(2, $tag->position);
        $this->assertFalse($tag->is_active);
    }

    #[Test]
    public function a_type_from_before_the_vocabulary_can_be_echoed_back(): void
    {
        // The admin select keeps an out-of-vocabulary current type for the same
        // reason: refusing it would make every read-modify-write fail.
        $this->declareTagTypes();
        $this->tag(['type' => 'legacy']);

        $result = app(UpdateTag::class)(['slug' => 'horror', 'type' => 'legacy', 'position' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame('legacy', $result['data']['type']);
    }

    #[Test]
    public function switching_to_an_undeclared_type_is_refused_and_nothing_changes(): void
    {
        $this->declareTagTypes();
        $tag = $this->tag(['type' => 'genre']);

        try {
            app(UpdateTag::class)(['slug' => 'horror', 'type' => 'mood', 'name' => ['en' => 'Changed']]);
            $this->fail('An undeclared type must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('genre', $tag->fresh()->type);
        $this->assertSame('Horror', $tag->fresh()->translated('name', 'en'));
    }

    #[Test]
    public function an_unknown_slug_is_not_found(): void
    {
        $result = app(UpdateTag::class)(['slug' => 'nope', 'position' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(['slug' => 'nope'], $result['target']);
    }

    #[Test]
    public function it_refuses_a_caller_without_the_admin_scope_and_changes_nothing(): void
    {
        $tag = $this->tag();
        request()->attributes->set('agent.is_admin', false);

        $result = app(UpdateTag::class)(['slug' => 'horror', 'is_active' => false]);

        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertTrue($tag->fresh()->is_active);
    }

    #[Test]
    public function the_schema_offers_no_way_to_rename_the_slug(): void
    {
        $properties = array_keys(app(UpdateTag::class)->inputSchema()['properties']);

        $this->assertNotContains('new_slug', $properties);
        $this->assertFalse(app(UpdateTag::class)->inputSchema()['additionalProperties']);
    }

    /** @param array<string, mixed> $attributes */
    private function tag(array $attributes = []): Tag
    {
        $tag = Tag::factory()->create(['slug' => 'horror', ...$attributes]);
        $tag->setTranslation('name', 'en', 'Horror');
        $tag->setTranslation('name', 'es', 'Terror');

        return $tag;
    }

    private function declareTagTypes(): void
    {
        config(['taxonomy.tag_types' => ['genre' => 'Genre', 'hidden_on_front' => 'Hidden']]);
    }
}
