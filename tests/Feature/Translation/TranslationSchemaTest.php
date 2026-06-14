<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Translation;

use InOtherShops\Tests\Stubs\TestTranslatable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use InOtherShops\Translation\Filament\TranslationSchema;

final class TranslationSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_a_scalar_translation_value(): void
    {
        $record = TestTranslatable::factory()->create();

        TranslationSchema::saveFormData($record, [
            'translations' => [
                'en' => ['name' => 'Silver ring', 'description' => '<p>Handmade.</p>'],
            ],
        ]);

        $this->assertSame('Silver ring', $record->translations()->where('field', 'name')->value('value'));
        $this->assertSame('<p>Handmade.</p>', $record->translations()->where('field', 'description')->value('value'));
    }

    #[Test]
    public function it_deletes_a_translation_when_the_value_is_blank(): void
    {
        $record = TestTranslatable::factory()->create();
        $record->setTranslation('name', 'en', 'Old name');

        TranslationSchema::saveFormData($record, [
            'translations' => ['en' => ['name' => '']],
        ]);

        $this->assertSame(0, $record->translations()->where('field', 'name')->count());
    }

    #[Test]
    public function it_throws_a_clear_error_when_handed_a_non_scalar_value(): void
    {
        $record = TestTranslatable::factory()->create();

        // A Filament RichEditor's raw $this->data state is a TipTap document
        // array — the shape that must never reach the string `value` column.
        $richEditorDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-scalar value for field "description"');

        TranslationSchema::saveFormData($record, [
            'translations' => [
                'en' => ['name' => 'Silver ring', 'description' => $richEditorDoc],
            ],
        ]);
    }

    #[Test]
    public function the_guard_does_not_persist_the_offending_translation(): void
    {
        $record = TestTranslatable::factory()->create();

        try {
            TranslationSchema::saveFormData($record, [
                'translations' => [
                    'en' => ['description' => ['type' => 'doc']],
                ],
            ]);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, $record->translations()->where('field', 'description')->count());
    }
}
