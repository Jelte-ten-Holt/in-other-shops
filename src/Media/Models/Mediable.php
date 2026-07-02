<?php

declare(strict_types=1);

namespace InOtherShops\Media\Models;

use InOtherShops\Media\Database\Factories\MediableFactory;
use InOtherShops\Media\Media as MediaRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class Mediable extends MorphPivot
{
    use HasFactory;

    protected $table = 'mediables';

    protected static string $factory = MediableFactory::class;

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_cover' => 'boolean',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaRegistry::media()::class);
    }

    public function isImage(): bool
    {
        return $this->media->isImage();
    }

    public function url(): string
    {
        return $this->media->url();
    }
}
