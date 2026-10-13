<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\AttendeeSourceResource;

class ListAttendeeSources extends ListRecords
{
    protected static string $resource = AttendeeSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
