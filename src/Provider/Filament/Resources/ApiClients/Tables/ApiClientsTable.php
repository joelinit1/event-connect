<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients\Tables;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Jma\Connect\Data\Dates;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Schemas\TokenFields;
use Jma\Connect\Provider\Models\ApiClient;

class ApiClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('tenant.'.config('connect.provider.tenant_label', 'name'))
                    ->label('Client')
                    ->placeholder('—')
                    ->visible(fn () => filled(config('connect.provider.tenant_model'))),
                TextColumn::make('tokens_count')
                    ->counts('tokens')
                    ->label('Active tokens')
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'warning' : 'gray'),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->state(fn (ApiClient $record) => $record->tokens()->max('last_used_at'))
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('issueToken')
                    ->label('Issue new token')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema(TokenFields::components())
                    ->action(function (ApiClient $record, array $data): void {
                        TokenFields::notifyIssued(
                            $record->issueToken($data['abilities'], Dates::parse($data['expires_at'] ?? null)),
                        );
                    }),
                Action::make('revokeTokens')
                    ->label('Revoke all tokens')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (ApiClient $record): void {
                        $record->tokens()->delete();

                        Notification::make()->title('Tokens revoked')->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }
}
