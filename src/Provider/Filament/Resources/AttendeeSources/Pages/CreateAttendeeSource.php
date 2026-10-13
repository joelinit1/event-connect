<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\AttendeeSourceResource;

class CreateAttendeeSource extends CreateRecord
{
    protected static string $resource = AttendeeSourceResource::class;

    /**
     * Straight to the edit page, where Preview is one click away.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
