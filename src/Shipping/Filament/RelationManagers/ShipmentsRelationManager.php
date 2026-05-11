<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Filament\RelationManagers;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Shipping\Actions\DispatchShipment;
use InOtherShops\Shipping\Actions\MarkShipmentDelivered;
use InOtherShops\Shipping\Actions\MarkShipmentLost;
use InOtherShops\Shipping\Actions\MarkShipmentReady;
use InOtherShops\Shipping\Actions\MarkShipmentReturnedToSender;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Models\Shipment;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (ShipmentStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ShipmentStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('method'),
                Tables\Columns\TextColumn::make('carrier')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->url(fn (Shipment $record): ?string => $record->tracking_url, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Shipped')
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                static::markReadyAction(),
                static::dispatchAction(),
                static::markDeliveredAction(),
                static::markReturnedToSenderAction(),
                static::markLostAction(),
            ]);
    }

    private static function markReadyAction(): Actions\Action
    {
        return Actions\Action::make('markReady')
            ->label('Mark ready')
            ->icon('heroicon-o-archive-box')
            ->color('info')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Ready))
            ->requiresConfirmation()
            ->action(function (Shipment $record): void {
                app(MarkShipmentReady::class)($record);

                Notification::make()
                    ->title('Shipment marked ready')
                    ->success()
                    ->send();
            });
    }

    private static function dispatchAction(): Actions\Action
    {
        return Actions\Action::make('dispatch')
            ->label('Dispatch')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::InTransit)
                && $record->shippableIsPaid())
            ->form([
                static::carrierField(),
                TextInput::make('tracking_number')
                    ->required()
                    ->maxLength(128),
                TextInput::make('tracking_url')
                    ->url()
                    ->maxLength(1024)
                    ->helperText('Leave blank to derive from the carrier template (config/shipping.carriers).'),
            ])
            ->action(function (Shipment $record, array $data): void {
                if (! $record->shippableIsPaid()) {
                    Notification::make()
                        ->title('Shipment cannot be dispatched')
                        ->body('The associated payable is not paid in full.')
                        ->danger()
                        ->send();

                    return;
                }

                app(DispatchShipment::class)(
                    $record,
                    $data['tracking_number'],
                    $data['carrier'],
                    $data['tracking_url'] ?: null,
                );

                Notification::make()
                    ->title('Shipment dispatched')
                    ->success()
                    ->send();
            });
    }

    private static function markDeliveredAction(): Actions\Action
    {
        return Actions\Action::make('markDelivered')
            ->label('Mark delivered')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Delivered))
            ->requiresConfirmation()
            ->action(function (Shipment $record): void {
                app(MarkShipmentDelivered::class)($record);

                Notification::make()
                    ->title('Shipment marked delivered')
                    ->success()
                    ->send();
            });
    }

    private static function markReturnedToSenderAction(): Actions\Action
    {
        return Actions\Action::make('markReturnedToSender')
            ->label('Mark returned to sender')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::ReturnedToSender))
            ->form([
                Textarea::make('reason')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Shipment $record, array $data): void {
                app(MarkShipmentReturnedToSender::class)($record, $data['reason']);

                Notification::make()
                    ->title('Shipment marked returned to sender')
                    ->success()
                    ->send();
            });
    }

    /**
     * Carrier picker — a Select sourced from config('shipping.carriers')
     * when carriers are configured, falling back to a free-text input
     * otherwise. Picking from config keeps Shipment.carrier aligned with
     * the carriers that have tracking_url_templates so DispatchShipment
     * can auto-derive the URL.
     */
    private static function carrierField(): Select|TextInput
    {
        $carriers = config('shipping.carriers', []);

        if (! is_array($carriers) || $carriers === []) {
            return TextInput::make('carrier')
                ->required()
                ->maxLength(64);
        }

        $options = [];
        foreach ($carriers as $identifier => $config) {
            $options[$identifier] = is_array($config) && isset($config['name'])
                ? $config['name']
                : $identifier;
        }

        return Select::make('carrier')
            ->options($options)
            ->required()
            ->searchable();
    }

    private static function markLostAction(): Actions\Action
    {
        return Actions\Action::make('markLost')
            ->label('Mark lost')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Lost))
            ->form([
                Textarea::make('reason')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Shipment $record, array $data): void {
                app(MarkShipmentLost::class)($record, $data['reason']);

                Notification::make()
                    ->title('Shipment marked lost')
                    ->success()
                    ->send();
            });
    }
}
