<?php

namespace Jma\Connect\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Shaped like a real check-in app's attendee: a required, unique token that
 * only the model generates, event_id not fillable, a JSON cast, soft
 * deletes, and a hook with a side effect (an email) on create.
 */
class ScannerAttendee extends Model
{
    use SoftDeletes;

    protected $table = 'scanner_attendees';

    protected $fillable = ['name', 'email', 'phone'];

    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attendee): void {
            $attendee->token ??= Str::upper(Str::random(16));
        });

        static::created(function (self $attendee): void {
            Mail::raw("Welcome {$attendee->name}", fn ($message) => $message->to($attendee->email ?? 'nobody@example.com'));
        });
    }
}
