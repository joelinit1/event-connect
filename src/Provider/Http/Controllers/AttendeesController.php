<?php

namespace Jma\Connect\Provider\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Provider\Http\Resources\AttendeeResource;

class AttendeesController
{
    use ValidatesListing;

    public function __invoke(Request $request, AttendeeProvider $provider, string $event): AnonymousResourceCollection
    {
        [$updatedSince, $perPage] = $this->listingParameters($request);

        $paginator = $provider->attendees($request->user(), $event, $updatedSince, $perPage);

        return AttendeeResource::collection($paginator->withQueryString());
    }
}
