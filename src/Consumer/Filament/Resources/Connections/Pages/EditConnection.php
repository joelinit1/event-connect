<?php

namespace Jma\Connect\Consumer\Filament\Resources\Connections\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Jma\Connect\Consumer\Filament\Resources\Connections\ConnectionResource;

class EditConnection extends EditRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
