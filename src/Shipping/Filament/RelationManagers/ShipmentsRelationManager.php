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

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('shops-shipping::shipment.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->color(fn (ShipmentStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ShipmentStatus $state): string => $state->label()),
                Tables\Columns\TextColumn::make('method')
                    ->label(__('shops-shipping::shipment.columns.method')),
                Tables\Columns\TextColumn::make('carrier')
                    ->label(__('shops-shipping::shipment.columns.carrier'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label(__('shops-shipping::shipment.columns.tracking'))
                    ->placeholder('—')
                    ->url(fn (Shipment $record): ?string => $record->tracking_url, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label(__('shops-shipping::shipment.columns.shipped'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('delivered_at')
                    ->label(__('shops-shipping::shipment.columns.delivered'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-shipping::shipment.columns.created'))
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
            ->label(__('shops-shipping::shipment.actions.mark_ready'))
            ->icon('heroicon-o-archive-box')
            ->color('info')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Ready))
            ->requiresConfirmation()
            ->action(function (Shipment $record): void {
                app(MarkShipmentReady::class)($record);

                Notification::make()
                    ->title(__('shops-shipping::shipment.notifications.marked_ready'))
                    ->success()
                    ->send();
            });
    }

    private static function dispatchAction(): Actions\Action
    {
        return Actions\Action::make('dispatch')
            ->label(__('shops-shipping::shipment.actions.dispatch'))
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::InTransit)
                && $record->shippableIsPaid())
            ->form([
                static::carrierField(),
                TextInput::make('tracking_number')
                    ->label(__('shops-shipping::shipment.form.tracking_number'))
                    ->maxLength(128)
                    ->helperText(__('shops-shipping::shipment.form.tracking_number_help')),
                TextInput::make('tracking_url')
                    ->label(__('shops-shipping::shipment.form.tracking_url'))
                    ->url()
                    ->maxLength(1024)
                    ->helperText(__('shops-shipping::shipment.form.tracking_url_help')),
            ])
            ->action(function (Shipment $record, array $data): void {
                if (! $record->shippableIsPaid()) {
                    Notification::make()
                        ->title(__('shops-shipping::shipment.notifications.cannot_dispatch'))
                        ->body(__('shops-shipping::shipment.notifications.cannot_dispatch_body'))
                        ->danger()
                        ->send();

                    return;
                }

                // `?: null` on all three, not just the URL. With `required()`
                // gone an untouched Filament text input arrives as '', and ''
                // in `carrier` would be persisted as a carrier the shipment
                // does not have — read back as "there is a carrier, it just
                // prints as nothing". Absent means null in the column.
                app(DispatchShipment::class)(
                    $record,
                    $data['tracking_number'] ?: null,
                    $data['carrier'] ?: null,
                    $data['tracking_url'] ?: null,
                );

                Notification::make()
                    ->title(__('shops-shipping::shipment.notifications.dispatched'))
                    ->success()
                    ->send();
            });
    }

    private static function markDeliveredAction(): Actions\Action
    {
        return Actions\Action::make('markDelivered')
            ->label(__('shops-shipping::shipment.actions.mark_delivered'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Delivered))
            ->requiresConfirmation()
            ->action(function (Shipment $record): void {
                app(MarkShipmentDelivered::class)($record);

                Notification::make()
                    ->title(__('shops-shipping::shipment.notifications.marked_delivered'))
                    ->success()
                    ->send();
            });
    }

    private static function markReturnedToSenderAction(): Actions\Action
    {
        return Actions\Action::make('markReturnedToSender')
            ->label(__('shops-shipping::shipment.actions.mark_returned_to_sender'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::ReturnedToSender))
            ->form([
                Textarea::make('reason')
                    ->label(__('shops-shipping::shipment.form.reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Shipment $record, array $data): void {
                app(MarkShipmentReturnedToSender::class)($record, $data['reason']);

                Notification::make()
                    ->title(__('shops-shipping::shipment.notifications.marked_returned_to_sender'))
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
     *
     * OPTIONAL, like the tracking number beside it: untracked post has no
     * carrier reference to give, and a shop that sells it must still be able
     * to say the parcel has gone out. See DispatchShipment for the full
     * reasoning; one dispatch action covers both cases rather than a second
     * "mark as posted" wearing the same mechanic under another name.
     */
    private static function carrierField(): Select|TextInput
    {
        $carriers = config('shipping.carriers', []);

        if (! is_array($carriers) || $carriers === []) {
            return TextInput::make('carrier')
                ->label(__('shops-shipping::shipment.form.carrier'))
                ->maxLength(64);
        }

        $options = [];
        foreach ($carriers as $identifier => $config) {
            $options[$identifier] = is_array($config) && isset($config['name'])
                ? $config['name']
                : $identifier;
        }

        return Select::make('carrier')
            ->label(__('shops-shipping::shipment.form.carrier'))
            ->options($options)
            ->searchable();
    }

    private static function markLostAction(): Actions\Action
    {
        return Actions\Action::make('markLost')
            ->label(__('shops-shipping::shipment.actions.mark_lost'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Lost))
            ->form([
                Textarea::make('reason')
                    ->label(__('shops-shipping::shipment.form.reason'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Shipment $record, array $data): void {
                app(MarkShipmentLost::class)($record, $data['reason']);

                Notification::make()
                    ->title(__('shops-shipping::shipment.notifications.marked_lost'))
                    ->success()
                    ->send();
            });
    }
}
