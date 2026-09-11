<?php

namespace Jma\Connect\Provider\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Jma\Connect\Data\Dates;

trait ValidatesListing
{
    /**
     * @return array{0: ?CarbonImmutable, 1: int}
     */
    protected function listingParameters(Request $request): array
    {
        $validated = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ]);

        return [
            Dates::parse($validated['updated_since'] ?? null),
            (int) ($validated['per_page'] ?? 100),
        ];
    }
}
