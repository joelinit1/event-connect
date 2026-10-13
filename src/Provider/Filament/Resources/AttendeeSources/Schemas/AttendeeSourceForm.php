<?php

namespace Jma\Connect\Provider\Filament\Resources\AttendeeSources\Schemas;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Jma\Connect\Data\AttendeeData;
use Jma\Connect\Filament\Components\FieldMapRepeater;
use Jma\Connect\Mapping\FieldMap;
use Jma\Connect\Mapping\Suggest;
use Jma\Connect\Support\LocalTables;

class AttendeeSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        $columns = fn (Get $get) => LocalTables::columnOptions($get('table_name'));

        return $schema
            ->columns(1)
            ->components([
                Section::make('Event')
                    ->description('Each source is one event to the apps that connect to this one.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->helperText('Shown to connecting apps, e.g. "Spring Gala 2027".')
                            ->required(),
                        TextInput::make('slug'),
                        // Only when the host has tenants: a hidden relationship field still
                        // loads its relation, which has no model class without one.
                        ...(filled(config('connect.provider.tenant_model')) ? [
                            Select::make('tenant_id')
                                ->label('Client')
                                ->relationship('tenant', config('connect.provider.tenant_label', 'name'))
                                ->searchable()
                                ->preload(),
                        ] : []),
                        Toggle::make('enabled')
                            ->helperText('Off hides the event from connecting apps.')
                            ->default(true),
                    ]),
                Section::make('Where the attendees are')
                    ->columns(2)
                    ->schema([
                        Select::make('table_name')
                            ->label('Table')
                            ->options(fn () => LocalTables::options())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $has = fn (string $column) => in_array($column, LocalTables::columns($state), true) ? $column : null;
                                $set('id_column', $has('id'));
                                $set('updated_at_column', $has('updated_at'));
                                $set('deleted_at_column', $has('deleted_at'));
                                $set('filter_column', $has('event_id'));
                                $set('filter_value', null);
                            }),
                        Select::make('id_column')
                            ->label('Unique ID column')
                            ->helperText('Must never change for an attendee: it\'s how the other app matches them up again.')
                            ->options($columns)
                            ->required(),
                        Select::make('filter_column')
                            ->label('Event column')
                            ->helperText('If this table holds more than one event\'s attendees, the column that says which event (usually event_id).')
                            ->options($columns)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('filter_value', null)),
                        Select::make('filter_value')
                            ->label('Event')
                            ->helperText('Only this event\'s attendees are shared.')
                            ->options(fn (Get $get) => self::eventChoices($get))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $label = self::eventChoices($get)[$state] ?? null;

                                if (blank($get('name')) && $label !== null) {
                                    $set('name', preg_replace('/ \(#[^)]*\)$/', '', $label));
                                }
                            })
                            ->required(fn (Get $get) => filled($get('filter_column')))
                            ->visible(fn (Get $get) => filled($get('filter_column')) && self::eventChoices($get) !== []),
                        TextInput::make('filter_value')
                            ->key('filter_value_typed')
                            ->label('Event value')
                            ->helperText('Rows whose event column equals this are shared.')
                            ->required(fn (Get $get) => filled($get('filter_column')))
                            ->visible(fn (Get $get) => filled($get('filter_column')) && self::eventChoices($get) === []),
                        Select::make('updated_at_column')
                            ->label('Last-updated column')
                            ->helperText('Lets syncs fetch only changed rows. Without it, every sync reads the whole table.')
                            ->options($columns),
                        Select::make('deleted_at_column')
                            ->label('Soft-delete column')
                            ->helperText('Lets connected apps hear about removed attendees.')
                            ->options($columns),
                    ]),
                Section::make('Fields')
                    ->description('Map at least first_name. Anything without a named field goes under extra.<name>, which the other app can map to any column.')
                    ->schema([
                        FieldMapRepeater::make(
                            fromOptions: fn (Closure $field) => self::sourcePaths($field('table_name')),
                            toOptions: null,
                            toSuggestions: fn () => [...AttendeeData::WIRE_FIELDS, 'extra', ...array_map(fn ($k) => "extra.{$k}", ['phone', 'dietary'])],
                            suggest: fn (Closure $field) => Suggest::forProvider(
                                LocalTables::columns($field('table_name')),
                                self::sample($field('table_name')),
                                array_filter([$field('id_column'), $field('filter_column'), $field('updated_at_column'), $field('deleted_at_column')]),
                            ),
                            suggestLabel: 'Suggest from table',
                        ),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function eventChoices(Get $get): array
    {
        return LocalTables::valueOptions($get('table_name'), $get('filter_column'));
    }

    /**
     * Columns, plus the keys inside any JSON columns of a sample row.
     *
     * @return list<string>
     */
    private static function sourcePaths(?string $table): array
    {
        $paths = LocalTables::columns($table);
        $sample = self::sample($table);

        if ($sample !== null) {
            $paths = [...$paths, ...array_keys(FieldMap::flatten($sample))];
        }

        return array_values(array_unique(array_filter($paths, fn (string $p) => ! Suggest::isSensitive($p))));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function sample(?string $table): ?array
    {
        if (LocalTables::columns($table) === []) {
            return null;
        }

        $row = DB::table($table)->first();

        return $row === null ? null : (array) $row;
    }
}
