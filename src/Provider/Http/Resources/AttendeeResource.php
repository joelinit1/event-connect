<?php

namespace Jma\Connect\Provider\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Jma\Connect\Data\AttendeeData;

/**
 * @mixin AttendeeData
 */
class AttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
