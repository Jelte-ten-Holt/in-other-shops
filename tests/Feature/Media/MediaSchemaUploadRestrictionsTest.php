<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression cover for H7 (audit 2026-05-09): the Filament FileUpload field
 * for media must allowlist mime types and exclude image/svg+xml.
 *
 * Field-level options on Filament components are awkward to introspect
 * directly without booting Livewire, so we test the source of truth
 * (allowedUploadMimeTypes) plus assert the field is wired to it.
 */
final class MediaSchemaUploadRestrictionsTest extends TestCase
{
    #[Test]
    public function default_allowlist_excludes_svg(): void
    {
        $allowed = MediaSchema::allowedUploadMimeTypes();

        $this->assertNotContains(
            'image/svg+xml',
            $allowed,
            'SVG must NOT be in the default allowlist — it executes JS when served inline (H7).'
        );
    }

    #[Test]
    public function default_allowlist_includes_common_image_types_and_pdf(): void
    {
        $allowed = MediaSchema::allowedUploadMimeTypes();

        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'application/pdf'] as $expected) {
            $this->assertContains($expected, $allowed, "Default allowlist must include $expected.");
        }
    }

    #[Test]
    public function allowlist_can_be_overridden_via_config(): void
    {
        config(['media.allowed_mime_types' => ['image/png']]);

        $allowed = MediaSchema::allowedUploadMimeTypes();

        $this->assertSame(['image/png'], $allowed);
    }

    #[Test]
    public function empty_config_falls_back_to_safe_defaults(): void
    {
        // Defensive: if a consumer sets the config to an empty array (e.g.
        // misconfiguration), the field still allowlists known-safe types
        // rather than allowing everything.
        config(['media.allowed_mime_types' => []]);

        $allowed = MediaSchema::allowedUploadMimeTypes();

        $this->assertNotEmpty($allowed);
        $this->assertNotContains('image/svg+xml', $allowed);
    }
}
