<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Jma\Connect\Filament\Concerns\AuthorizesViaGate;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\CreateAttendeeSource;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\EditAttendeeSource;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Pages\ListAttendeeSources;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Schemas\AttendeeSourceForm;
use Jma\Connect\Provider\Filament\Resources\AttendeeSources\Tables\AttendeeSourcesTable;
use Jma\Connect\Provider\Models\AttendeeSource;
use Jma\Connect\Provider\SourcePreview;
use UnitEnum;

/**
 * Which local table holds each event's attendees, and how its columns map
 * to the wire format. Each source appears to API clients as one event.
 */
class AttendeeSourceResource extends Resource
{
    use AuthorizesViaGate;

    protected static ?string $model = AttendeeSource::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'connect-attendee-sources';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Platform Admin';

    protected static ?string $navigationLabel = 'Attendee Sources';

    protected static ?string $modelLabel = 'attendee source';

    public static function form(Schema $schema): Schema
    {
        return AttendeeSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendeeSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendeeSources::route('/'),
            'create' => CreateAttendeeSource::route('/create'),
            'edit' => EditAttendeeSource::route('/{record}/edit'),
        ];
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (AttendeeSource $record) => "What {$record->name} sends")
            ->modalDescription('The first live rows, exactly as API clients will receive them.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (AttendeeSource $record) => view('connect::preview', SourcePreview::build($record)))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }
}
