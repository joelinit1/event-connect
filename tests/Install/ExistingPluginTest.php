<?php

use Filament\Facades\Filament;
use Jma\Connect\Tests\ExistingPluginTestCase;

uses(ExistingPluginTestCase::class);

it('leaves panels alone when the host registered the plugin itself', function () {
    expect(Filament::getPanel('platform')->hasPlugin('connect'))->toBeTrue()
        ->and(Filament::getPanel('admin')->hasPlugin('connect'))->toBeFalse();
});
