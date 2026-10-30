<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Agent\Tools\CreateTag;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class CreateTagToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_creates_a_tag_with_a_name_per_locale_and_derives_the_slug(): void
    {
        config(['translation.locales' => ['en', 'es']]);

        $result = app(CreateTag::class)(['name' => ['en' => 'Horror', 'es' => 'Terror']]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['slug' => 'horror'], $result['target']);
        $this->assertSame(['en' => 'Horror', 'es' => 'Terror'], $result['data']['names']);
        $this->assertNull($result['data']['type']);
        $this->assertSame(0, $result['data']['position']);
        $this->assertTrue($result['data']['is_active']);

        $tag = Tag::query()->where('slug', 'horror')->sole();
        $this->assertSame('Terror', $tag->translated('name', 'es'));
        $this->assertTrue($tag->is_active);
    }

    #[Test]
    public function it_stores_an_explicit_slug_position_and_inactive_flag(): void
    {
        $result = app(CreateTag::class)([
            'name' => ['en' => 'One-page games'],
            'slug' => 'one-pager',
            'position' => 3,
            'is_active' => false,
        ]);

        $this->assertTrue($result['ok']);
        $tag = Tag::query()->where('slug', 'one-pager')->sole();
        $this->assertSame(3, $tag->position);
        $this->assertFalse($tag->is_active);
    }

    #[Test]
    public function it_refuses_a_caller_without_the_admin_scope_and_writes_nothing(): void
    {
        request()->attributes->set('agent.is_admin', false);

        $result = app(CreateTag::class)(['name' => ['en' => 'Horror']]);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame(0, Tag::query()->count());
    }

    #[Test]
    public function an_existing_slug_is_refused_and_that_tag_is_left_alone(): void
    {
        $existing = Tag::factory()->create(['slug' => 'horror']);
        $existing->setTranslation('name', 'en', 'Original');

        $result = app(CreateTag::class)(['name' => ['en' => 'Horror']]);

        $this->assertFalse($result['ok']);
        $this->assertSame('slug_taken', $result['error']['code']);
        $this->assertSame(1, Tag::query()->count());
        $this->assertSame('Original', $existing->fresh()->translated('name', 'en'));
    }

    #[Test]
    public function the_name_must_include_the_default_locale(): void
    {
        // A Spanish-first shop: a tag named only in English renders nameless
        // on every Spanish page.
        config(['translation.locales' => ['es', 'en'], 'translation.default' => 'es']);

        try {
            app(CreateTag::class)(['name' => ['en' => 'Horror']]);
            $this->fail('A name without the default locale must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'es'", $e->getMessage());
        }

        $this->assertSame(0, Tag::query()->count());
    }

    #[Test]
    public function a_locale_the_project_does_not_have_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown locale 'de'");

        app(CreateTag::class)(['name' => ['en' => 'Horror', 'de' => 'Grusel']]);
    }

    #[Test]
    public function a_name_given_as_a_plain_string_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CreateTag::class)(['name' => 'Horror']);
    }

    #[Test]
    public function an_explicit_slug_must_already_be_in_slug_form(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CreateTag::class)(['name' => ['en' => 'One pager'], 'slug' => 'One Pager']);
    }

    #[Test]
    public function a_name_that_yields_no_slug_is_refused_rather_than_stored_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No slug can be derived');

        app(CreateTag::class)(['name' => ['en' => '!!!']]);
    }

    #[Test]
    public function without_a_declared_vocabulary_the_type_is_free_text(): void
    {
        $result = app(CreateTag::class)(['name' => ['en' => 'Horror'], 'type' => 'mood']);

        $this->assertSame('mood', $result['data']['type']);
        $this->assertArrayNotHasKey('enum', app(CreateTag::class)->inputSchema()['properties']['type']);
    }

    #[Test]
    public function with_a_declared_vocabulary_the_type_must_be_stated(): void
    {
        $this->declareTagTypes();

        try {
            app(CreateTag::class)(['name' => ['en' => 'Horror']]);
            $this->fail('An unstated type must be refused when the project declares a vocabulary.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'type' is required", $e->getMessage());
        }

        $this->assertSame(0, Tag::query()->count());
    }

    #[Test]
    public function with_a_declared_vocabulary_a_declared_type_or_null_is_stored(): void
    {
        $this->declareTagTypes();

        $genre = app(CreateTag::class)(['name' => ['en' => 'Horror'], 'type' => 'genre']);
        $untyped = app(CreateTag::class)(['name' => ['en' => 'Interview'], 'type' => null]);

        $this->assertSame('genre', Tag::query()->where('slug', 'horror')->value('type'));
        $this->assertNull(Tag::query()->where('slug', 'interview')->value('type'));
        $this->assertSame('genre', $genre['data']['type']);
        $this->assertNull($untyped['data']['type']);
    }

    #[Test]
    public function with_a_declared_vocabulary_an_undeclared_type_is_refused(): void
    {
        $this->declareTagTypes();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown tag type 'mood'");

        app(CreateTag::class)(['name' => ['en' => 'Horror'], 'type' => 'mood']);
    }

    #[Test]
    public function the_schema_requires_the_type_and_explains_each_declared_one(): void
    {
        $this->declareTagTypes();

        $schema = app(CreateTag::class)->inputSchema();

        $this->assertContains('type', $schema['required']);
        $this->assertSame(['genre', 'hidden_on_front', null], $schema['properties']['type']['enum']);
        $this->assertStringContainsString('Never shown publicly.', $schema['properties']['type']['description']);
    }

    private function declareTagTypes(): void
    {
        config(['taxonomy.tag_types' => [
            'genre' => ['label' => 'Genre', 'description' => 'What kind of thing this is.'],
            'hidden_on_front' => ['label' => 'Hidden', 'description' => 'Never shown publicly.'],
        ]]);
    }
}
