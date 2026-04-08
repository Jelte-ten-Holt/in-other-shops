<?php

declare(strict_types=1);

namespace InOtherShops\Media\Filament\RelationManagers;

use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    public function form(Schema $schema): Schema
    {
        $disk = config('media.disk');
        $directory = config('media.directory');
        $collections = config('media.collections', []);
        $collectionOptions = array_map(
            fn (array $config) => __($config['label']),
            $collections,
        );

        return $schema
            ->schema([
                FileUpload::make('path')
                    ->required()
                    ->disk($disk)
                    ->directory($directory)
                    ->visibility('public')
                    ->columnSpanFull(),
                Select::make('collection')
                    ->options($collectionOptions)
                    ->required(),
                TextInput::make('alt')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('path')
                    ->label('Thumbnail')
                    ->disk(fn ($record) => $record->disk)
                    ->square()
                    ->size(40),
                Tables\Columns\TextColumn::make('filename')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pivot.collection')
                    ->label('Collection')
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('size')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('alt')
                    ->placeholder('—'),
            ])
            ->defaultSort('mediables.position')
            ->reorderable('mediables.position')
            ->headerActions([
                Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        return $this->enrichFormData($data);
                    })
                    ->after(function (Media $record, array $data): void {
                        $this->getOwnerRecord()->media()->attach($record->id, [
                            'collection' => $data['collection'] ?? '',
                            'position' => 0,
                        ]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DetachAction::make()
                    ->after(function (Media $record): void {
                        $record->delete();
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }

    private function enrichFormData(array $data): array
    {
        $disk = config('media.disk');
        $data['disk'] = $disk;
        $data['type'] = MediaType::Upload;

        if (isset($data['path'])) {
            $storage = Storage::disk($disk);
            $data['filename'] = basename($data['path']);
            $data['mime_type'] = $storage->mimeType($data['path']) ?: 'application/octet-stream';
            $data['size'] = $storage->size($data['path']) ?: 0;
        }

        return $data;
    }
}
