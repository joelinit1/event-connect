<?php

namespace Jma\Connect\Filament\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Jma\Connect\Mapping\Transform;
use Throwable;

/**
 * The field-map editor used on both sides: one row per rule, with a
 * "Suggest" button that fills in rules matched by column name. Existing
 * rules are kept; suggestions only add rules for targets not yet mapped.
 */
final class FieldMapRepeater
{
    /**
     * Every closure receives $field: fn (string $name) => the form's
     * top-level value for $name, wherever in the form it's called from.
     *
     * @param  Closure(Closure): array<string>  $fromOptions  Paths offered in the "From" box
     * @param  (Closure(Closure): array<string, string>)|null  $toOptions  Choices for a "To" dropdown
     * @param  (Closure(Closure): array<string>)|null  $toSuggestions  Or: suggestions for a free-text "To"
     * @param  Closure(Closure): list<array<string, mixed>>  $suggest  Suggested rules
     */
    public static function make(Closure $fromOptions, ?Closure $toOptions, ?Closure $toSuggestions, Closure $suggest, string $suggestLabel): Repeater
    {
        $inItem = fn (Get $get) => fn (string $name) => $get("../../{$name}");

        return Repeater::make('field_map')
            ->label('Field map')
            ->helperText('From: a column, a path into a JSON column (rsvp.dietary), every key in one (rsvp.*), or a template ({first_name} {last_name}). Default fills empty values.')
            ->table([
                TableColumn::make('From'),
                TableColumn::make('To'),
                TableColumn::make('Transform'),
                TableColumn::make('Default'),
            ])
            ->schema([
                TextInput::make('from')
                    ->datalist(fn (Get $get) => $fromOptions($inItem($get)))
                    ->placeholder('column or path'),
                $toOptions !== null
                    ? Select::make('to')
                        ->options(fn (Get $get) => $toOptions($inItem($get)))
                        ->searchable()
                        ->required()
                    : TextInput::make('to')
                        ->datalist(fn (Get $get) => $toSuggestions ? $toSuggestions($inItem($get)) : [])
                        ->required(),
                Select::make('transform')
                    ->options(Transform::options())
                    ->placeholder('none'),
                TextInput::make('default')
                    ->placeholder('—'),
            ])
            ->defaultItems(0)
            ->addActionLabel('Add rule')
            ->reorderable(false)
            ->hintAction(
                Action::make('suggest')
                    ->label($suggestLabel)
                    ->icon(Heroicon::OutlinedSparkles)
                    ->action(function (Get $get, Set $set) use ($suggest): void {
                        try {
                            $suggested = $suggest(fn (string $name) => $get($name));
                        } catch (Throwable $e) {
                            Notification::make()->title('Couldn\'t suggest a mapping')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        $current = array_values($get('field_map') ?? []);
                        $mapped = array_column($current, 'to');
                        $added = array_values(array_filter($suggested, fn (array $rule) => ! in_array($rule['to'], $mapped, true)
                            || str_starts_with((string) $rule['from'], '{')));
                        $added = array_values(array_filter($added, fn (array $rule) => ! in_array($rule, $current, false)));

                        $set('field_map', collect([...$current, ...$added])->keyBy(fn () => (string) Str::uuid())->all());

                        Notification::make()
                            ->title($added === [] ? 'Nothing new to suggest' : 'Added '.count($added).' suggested rule(s)')
                            ->body($added === [] ? null : 'Check them, then Save. Preview shows the result on real rows.')
                            ->success()
                            ->send();
                    }),
            );
    }
}
