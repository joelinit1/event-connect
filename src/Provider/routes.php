<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Jma\Connect\Connect;
use Jma\Connect\Provider\Http\Controllers\AttendeesController;
use Jma\Connect\Provider\Http\Controllers\EventsController;
use Jma\Connect\Provider\Http\Middleware\ForceJsonResponse;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::prefix(Connect::API_PREFIX)
    ->middleware([ForceJsonResponse::class, 'throttle:connect', SubstituteBindings::class, 'auth:sanctum'])
    ->name('connect.')
    ->group(function () {
        Route::get('events', EventsController::class)
            ->middleware(CheckAbilities::class.':'.Connect::ABILITY_EVENTS_READ)
            ->name('events');

        Route::get('events/{event}/attendees', AttendeesController::class)
            ->middleware(CheckAbilities::class.':'.Connect::ABILITY_ATTENDEES_READ)
            ->name('attendees');
    });
