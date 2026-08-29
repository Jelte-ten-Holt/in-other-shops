<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Taxonomy;

use InOtherShops\Taxonomy\Support\TagTypes;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tag-type vocabulary is the consuming project's, not the package's. These
 * pin the two properties that make that safe to ship: an undeclared vocabulary
 * changes nothing for existing consumers, and a declared one never destroys a
 * value it does not happen to contain.
 */
final class TagTypesTest extends TestCase
{
    #[Test]
    public function a_project_that_declares_nothing_keeps_free_text(): void
    {
        config()->set('taxonomy.tag_types', []);

        $this->assertFalse(TagTypes::isConfigured());
        $this->assertSame([], TagTypes::options());
    }

    /** A malformed config must degrade to free text, not fatal in the admin. */
    #[Test]
    public function a_non_array_configuration_is_ignored(): void
    {
        config()->set('taxonomy.tag_types', 'genre,disclosure');

        $this->assertFalse(TagTypes::isConfigured());
    }

    #[Test]
    public function plain_labels_are_accepted(): void
    {
        config()->set('taxonomy.tag_types', ['genre' => 'Genre', 'disclosure' => 'Disclosure']);

        $this->assertTrue(TagTypes::isConfigured());
        $this->assertSame(['genre' => 'Genre', 'disclosure' => 'Disclosure'], TagTypes::options());
        $this->assertSame([], TagTypes::descriptions());
    }

    #[Test]
    public function a_label_and_description_pair_is_accepted(): void
    {
        config()->set('taxonomy.tag_types', [
            'genre' => 'Genre',
            'disclosure' => ['label' => 'Disclosure', 'description' => 'How the work was made.'],
        ]);

        $this->assertSame(['genre' => 'Genre', 'disclosure' => 'Disclosure'], TagTypes::options());
        $this->assertSame(['disclosure' => 'How the work was made.'], TagTypes::descriptions());
    }

    /** A declared value with no label at all falls back to the key, never blank. */
    #[Test]
    public function a_missing_label_falls_back_to_the_value(): void
    {
        config()->set('taxonomy.tag_types', ['genre' => [], 'disclosure' => '']);

        $this->assertSame(['genre' => 'genre', 'disclosure' => 'disclosure'], TagTypes::options());
    }

    /**
     * The one that matters. A tag typed before the vocabulary existed — or
     * typed with a value since removed from it — must stay selectable, or the
     * select renders empty and the next save of any unrelated field silently
     * wipes a value the editor never touched and never saw.
     */
    #[Test]
    public function an_existing_value_outside_the_vocabulary_is_preserved(): void
    {
        config()->set('taxonomy.tag_types', ['genre' => 'Genre']);

        $options = TagTypes::options('legacy_type');

        $this->assertArrayHasKey('legacy_type', $options);
        $this->assertArrayHasKey('genre', $options);
    }

    #[Test]
    public function a_current_value_already_in_the_vocabulary_is_not_duplicated(): void
    {
        config()->set('taxonomy.tag_types', ['genre' => 'Genre']);

        $this->assertSame(['genre' => 'Genre'], TagTypes::options('genre'));
    }

    /** An untyped tag adds nothing — no empty-string option in the list. */
    #[Test]
    public function a_null_or_empty_current_value_adds_no_option(): void
    {
        config()->set('taxonomy.tag_types', ['genre' => 'Genre']);

        $this->assertSame(['genre' => 'Genre'], TagTypes::options(null));
        $this->assertSame(['genre' => 'Genre'], TagTypes::options(''));
    }
}
