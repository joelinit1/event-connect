<?php

namespace Jma\Connect\Provider\Models;

use DateTimeInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * The thing a Sanctum token belongs to on the provider side. Sanctum's guard
 * accepts any tokenable model as long as the host leaves
 * `auth.guards.sanctum.provider` at its default (null).
 */
class ApiClient extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    protected $table = 'connect_api_clients';

    protected $fillable = ['tenant_id', 'name'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('connect.provider.tenant_model'));
    }

    /**
     * @param  list<string>  $abilities
     * @return string The plaintext token — only available at issue time.
     */
    public function issueToken(array $abilities, ?DateTimeInterface $expiresAt): string
    {
        return $this->createToken($this->name, $abilities, $expiresAt)->plainTextToken;
    }
}
