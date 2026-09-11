<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Jma\Connect\Consumer\Filament\Resources\Connections\Pages\CreateConnection;
use Jma\Connect\Consumer\Filament\Resources\Connections\Pages\EditConnection;
use Jma\Connect\Consumer\Filament\Resources\Connections\Pages\ListConnections;
use Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers\SyncMappingsRelationManager;
use Jma\Connect\Consumer\Filament\Resources\Connections\RelationManagers\SyncRunsRelationManager;
use Jma\Connect\Consumer\Filament\Resources\Connections\Schemas\ConnectionForm;
use Jma\Connect\Consumer\Filament\Resources\Connections\Tables\ConnectionsTable;
use Jma\Connect\Consumer\Models\Connection;
use Jma\Connect\Filament\Concerns\AuthorizesViaGate;
use UnitEnum;

class ConnectionResource extends Resource
{
    use AuthorizesViaGate;

    protected static ?string $model = Connection::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'connect-connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Platform Admin';

    protected static ?string $navigationLabel = 'API Connections';

    protected static ?string $modelLabel = 'API connection';

    protected static ?string $pluralModelLabel = 'API connections';

    public static function form(Schema $schema): Schema
    {
        return ConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConnectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SyncMappingsRelationManager::class,
            SyncRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'create' => CreateConnection::route('/create'),
            'edit' => EditConnection::route('/{record}/edit'),
        ];
    }
}
