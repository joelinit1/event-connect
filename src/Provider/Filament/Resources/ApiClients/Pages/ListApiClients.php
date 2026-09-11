<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Jma\Connect\Provider\Filament\Resources\ApiClients\ApiClientResource;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
