<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A collection's accepted media types are part of what the collection means.
 * A video-embed collection that still offers "Upload" lets an editor put a JPEG
 * where a player URL belongs — the upload succeeds and the breakage only shows
 * up later as a dead embed on the public page.
 *
 * Same testing shape as MediaSchemaUploadRestrictionsTest: Filament field
 * options are awkward to introspect without booting Livewire, so we pin the
 * source of truth the field is wired to.
 */
final class MediaSchemaCollectionTypesTest extends TestCase
{
    #[Test]
    public function a_collection_without_a_types_key_accepts_every_type(): void
    {
        config()->set('media.collections', ['images' => ['label' => 'Images']]);

        $this->assertSame(MediaType::cases(), MediaSchema::collectionTypes('images'));
    }

    #[Test]
    public function a_collection_can_restrict_itself_to_a_single_type(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => ['embed']],
        ]);

        $this->assertSame([MediaType::Embed], MediaSchema::collectionTypes('embed'));
    }

    #[Test]
    public function restricting_types_excludes_upload(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => ['embed', 'external']],
        ]);

        $this->assertNotContains(
            MediaType::Upload,
            MediaSchema::collectionTypes('embed'),
            'A collection that excludes Upload must not offer it — that is the whole point.'
        );
    }

    #[Test]
    public function enum_cases_are_accepted_as_well_as_strings(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => [MediaType::Embed]],
        ]);

        $this->assertSame([MediaType::Embed], MediaSchema::collectionTypes('embed'));
    }

    /**
     * A config typo must not lock an editor out of their own media form, so an
     * unusable list falls back to every type rather than none.
     */
    #[Test]
    public function an_unrecognised_type_list_falls_back_to_every_type(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => ['vhs', 'betamax']],
        ]);

        $this->assertSame(MediaType::cases(), MediaSchema::collectionTypes('embed'));
    }

    #[Test]
    public function an_empty_type_list_falls_back_to_every_type(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => []],
        ]);

        $this->assertSame(MediaType::cases(), MediaSchema::collectionTypes('embed'));
    }

    #[Test]
    public function the_repeater_builds_without_error_for_a_restricted_collection(): void
    {
        config()->set('media.collections', [
            'embed' => ['label' => 'Embed', 'types' => ['embed'], 'cover' => false],
        ]);

        $repeater = MediaSchema::mediaRepeater('embed');

        $this->assertSame('_media.embed', $repeater->getName());
    }
}
