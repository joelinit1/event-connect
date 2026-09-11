<?php

namespace Jma\Connect\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Jma\Connect\Connect;

/**
 * Every package resource is admin-only: one host-defined Gate ability
 * decides everything, so hosts never need a policy per package model.
 */
trait AuthorizesViaGate
{
    public static function canViewAny(): bool
    {
        return Gate::allows(Connect::GATE);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows(Connect::GATE);
    }

    public static function canCreate(): bool
    {
        return Gate::allows(Connect::GATE);
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows(Connect::GATE);
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows(Connect::GATE);
    }

    public static function canDeleteAny(): bool
    {
        return Gate::allows(Connect::GATE);
    }
}
