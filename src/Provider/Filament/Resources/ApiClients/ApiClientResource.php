<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Jma\Connect\Filament\Concerns\AuthorizesViaGate;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Pages\CreateApiClient;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Pages\ListApiClients;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Schemas\ApiClientForm;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Tables\ApiClientsTable;
use Jma\Connect\Provider\Models\ApiClient;
use UnitEnum;

class ApiClientResource extends Resource
{
    use AuthorizesViaGate;

    protected static ?string $model = ApiClient::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'connect-api-clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Platform Admin';

    protected static ?string $navigationLabel = 'API Clients';

    protected static ?string $modelLabel = 'API client';

    protected static ?string $pluralModelLabel = 'API clients';

    public static function form(Schema $schema): Schema
    {
        return ApiClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiClientsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiClients::route('/'),
            'create' => CreateApiClient::route('/create'),
        ];
    }
}
