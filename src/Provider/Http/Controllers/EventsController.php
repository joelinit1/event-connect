<?php

namespace Jma\Connect\Provider\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Jma\Connect\Contracts\AttendeeProvider;
use Jma\Connect\Provider\Http\Resources\EventResource;

class EventsController
{
    use ValidatesListing;

    public function __invoke(Request $request, AttendeeProvider $provider): AnonymousResourceCollection
    {
        [$updatedSince, $perPage] = $this->listingParameters($request);

        $paginator = $provider->events($request->user(), $updatedSince, $perPage);

        return EventResource::collection($paginator->withQueryString());
    }
}
