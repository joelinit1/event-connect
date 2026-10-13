<?php

namespace Jma\Connect\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Support\LocalTables;

/**
 * Two deliberately different attendee tables: what a provider app might
 * keep for one event, and what a consumer app keeps locally.
 */
final class Tables
{
    public static function providerGuests(): void
    {
        Schema::create('gala_guests', function (Blueprint $table) {
            $table->string('guest_ref')->primary();
            $table->string('event_code');
            $table->string('guest_name');
            $table->string('email_address')->nullable();
            $table->string('company_name')->nullable();
            $table->unsignedInteger('table_id')->nullable();
            $table->text('rsvp')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        LocalTables::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function guest(string $ref, string $name, array $overrides = []): void
    {
        DB::table('gala_guests')->insert([
            'guest_ref' => $ref,
            'event_code' => 'GALA27',
            'guest_name' => $name,
            'email_address' => strtolower(explode(' ', $name)[0]).'@example.com',
            'company_name' => 'Analytical Engines',
            'table_id' => 3,
            'rsvp' => json_encode(['dietary' => 'Vegan', 'plus_one' => true]),
            'password' => 'secret-hash',
            'updated_at' => '2026-03-01 12:00:00',
            'deleted_at' => null,
            ...$overrides,
        ]);
    }

    public static function consumerGuests(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->string('first_name');
            $table->string('surname')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('table_no')->nullable();
            $table->string('dietary')->nullable();
            $table->text('answers')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        LocalTables::flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function consumerFieldMap(): array
    {
        return [
            ['from' => 'first_name', 'to' => 'first_name'],
            ['from' => 'last_name', 'to' => 'surname'],
            ['from' => 'email', 'to' => 'email'],
            ['from' => 'table', 'to' => 'table_no'],
            ['from' => 'extra.dietary', 'to' => 'dietary'],
        ];
    }
}
