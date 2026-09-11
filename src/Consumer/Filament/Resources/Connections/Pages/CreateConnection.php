<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\Pages;

use Filament\Resources\Pages\CreateRecord;
use Jma\Connect\Consumer\Filament\Resources\Connections\ConnectionResource;

class CreateConnection extends CreateRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
