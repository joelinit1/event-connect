<?php

namespace Jma\Connect\Provider\Filament\Resources\ApiClients\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Jma\Connect\Data\Dates;
use Jma\Connect\Provider\Filament\Resources\ApiClients\ApiClientResource;
use Jma\Connect\Provider\Filament\Resources\ApiClients\Schemas\TokenFields;
use Jma\Connect\Provider\Models\ApiClient;

class CreateApiClient extends CreateRecord
{
    protected static string $resource = ApiClientResource::class;

    private ?string $plainTextToken = null;

    protected function handleRecordCreation(array $data): Model
    {
        $abilities = Arr::pull($data, 'abilities', []);
        $expiresAt = Dates::parse(Arr::pull($data, 'expires_at'));

        /** @var ApiClient $client */
        $client = static::getModel()::create($data);

        $this->plainTextToken = $client->issueToken($abilities, $expiresAt);

        return $client;
    }

    protected function afterCreate(): void
    {
        if ($this->plainTextToken !== null) {
            TokenFields::notifyIssued($this->plainTextToken);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
