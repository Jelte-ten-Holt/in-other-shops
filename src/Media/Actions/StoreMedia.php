<?php

declare(strict_types=1);

namespace InOtherShops\Media\Actions;

use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Events\MediaStored;
use InOtherShops\Media\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

final class StoreMedia
{
    public function __invoke(
        HasMedia&Model $model,
        UploadedFile $file,
        ?string $collection = null,
        ?string $alt = null,
        ?string $disk = null,
    ): Media {
        $disk ??= config('media.disk');
        $resolvedCollection = $collection ?? '';
        $path = $this->storeFile($file, $disk);
        $media = $this->createMediaRecord($file, $path, $disk, $alt);
        $this->attachToModel($model, $media, $resolvedCollection);

        MediaStored::dispatch($media, $resolvedCollection);

        return $media;
    }

    private function storeFile(UploadedFile $file, string $disk): string
    {
        return $file->store(config('media.directory'), $disk);
    }

    private function createMediaRecord(
        UploadedFile $file,
        string $path,
        string $disk,
        ?string $alt,
    ): Media {
        return Media::create([
            'type' => MediaType::Upload,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'alt' => $alt,
        ]);
    }

    private function attachToModel(HasMedia&Model $model, Media $media, ?string $collection): void
    {
        $nextPosition = $model->media()
            ->wherePivot('collection', $collection ?? '')
            ->max('mediables.position') + 1;

        $model->media()->attach($media->id, [
            'collection' => $collection ?? '',
            'position' => $nextPosition,
        ]);
    }
}
