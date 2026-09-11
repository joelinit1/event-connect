<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Jma\Connect\Consumer\Filament\Resources\Connections\ConnectionResource;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
