<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament;

use InOtherShops\Inventory\Models\StockMovement;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;

final class StockMovementsTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;
    use WithoutUrlPagination;

    #[Locked]
    public string $stockableType;

    #[Locked]
    public int $stockableId;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                StockMovement::query()
                    ->whereHas('stockItem', fn (Builder $query) => $query
                        ->where('stockable_type', $this->stockableType)
                        ->where('stockable_id', $this->stockableId)
                    )
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->sortable(),
                TextColumn::make('reason')
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->badge(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state !== null
                        ? (config("inventory.sources.{$state}") ?? $state)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function render(): string
    {
        return '{{ $this->table }}';
    }
}
