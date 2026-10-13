<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Jma\Connect\ConnectServiceProvider;
use Jma\Connect\Tests\ClientPanelTestCase;
use Jma\Connect\Tests\Fixtures\Models\Client;

uses(ClientPanelTestCase::class);

it('puts its screens on the super-admin panel, not the clients\' default one', function () {
    expect(Filament::getPanel('platform')->hasPlugin('connect'))->toBeTrue()
        ->and(Filament::getPanel('client')->hasPlugin('connect'))->toBeFalse();
});

/**
 * @param  list<array{0: string, 1: bool, 2: bool}>  $shape  [id, default, tenancy]
 */
function chosenPanel(array $shape): ?string
{
    $panels = collect($shape)->mapWithKeys(function (array $p) {
        $panel = Panel::make()->id($p[0])->path($p[0])->default($p[1]);

        return [$p[0] => $p[2] ? $panel->tenant(Client::class) : $panel];
    });

    return ConnectServiceProvider::adminPanel($panels)?->getId();
}

it('chooses the super admin\'s panel for each shape of app', function (array $shape, string $expected) {
    expect(chosenPanel($shape))->toBe($expected);
})->with([
    'one admin panel' => [[['admin', true, false]], 'admin'],
    'admin default, client portal beside it' => [[['admin', true, false], ['app', false, true]], 'admin'],
    'admin default, host and gateway panels' => [[['admin', true, false], ['gateway', false, false], ['host', false, false]], 'admin'],
    'client default with tenancy, platform beside it' => [[['client', true, true], ['platform', false, false]], 'platform'],
    'client default, platform and another' => [[['client', true, true], ['kiosk', false, false], ['platform', false, false]], 'platform'],
    'one shared panel with tenancy' => [[['admin', true, true]], 'admin'],
]);
