<?php

declare(strict_types=1);

namespace InOtherShops\Media\Models;

use InOtherShops\Media\Database\Factories\MediaFactory;
use InOtherShops\Media\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    protected static function newFactory(): Factory
    {
        return new MediaFactory;
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'type' => MediaType::class,
        ];
    }

    protected static function booted(): void
    {
        self::deleting(function (Media $media): void {
            if ($media->type === MediaType::Upload && $media->disk && $media->path) {
                Storage::disk($media->disk)->delete($media->path);
            }
        });
    }

    public function url(): string
    {
        return match ($this->type) {
            MediaType::Upload => Storage::disk($this->disk)->url($this->path),
            MediaType::External, MediaType::Embed => $this->getAttribute('url'),
        };
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
