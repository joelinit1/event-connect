<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\AttendeeSourceResource;

class EditAttendeeSource extends EditRecord
{
    protected static string $resource = AttendeeSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttendeeSourceResource::previewAction(),
            DeleteAction::make(),
        ];
    }
}
