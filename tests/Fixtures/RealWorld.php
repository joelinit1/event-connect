<?php

namespace Jma\Connect\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jma\Connect\Support\LocalTables;

/**
 * Tables shaped like real apps rather than tidy ones: a registration app
 * (provider) with events and a single full_name column, and a check-in app
 * (consumer) whose attendees need an event, have a model-generated token
 * and no column for another system's ID.
 */
final class RealWorld
{
    public static function registrationApp(): void
    {
        Schema::create('reg_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('reg_attendees', function (Blueprint $table) {
            $table->id();
            $table->string('check_in_code', 12)->nullable()->unique();
            $table->foreignId('event_id')->constrained('reg_events')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->json('rsvp_responses')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('reg_events')->insert([
            ['id' => 1, 'name' => 'Summer Party', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Winter Ball', 'created_at' => now(), 'updated_at' => now()],
        ]);

        LocalTables::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function registrant(string $name, array $overrides = []): int
    {
        return DB::table('reg_attendees')->insertGetId([
            'event_id' => 1,
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'check_in_code' => strtoupper(substr(md5($name), 0, 8)),
            'rsvp_responses' => json_encode(['dietary' => 'None', 'phone' => '07700 900123']),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            ...$overrides,
        ]);
    }

    public static function checkInApp(): void
    {
        Schema::create('scanner_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('signing_key');
            $table->timestamps();
        });

        Schema::create('scanner_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('scanner_events')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->string('source')->default('ad_hoc');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('answers')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('scanner_events')->insert([
            ['id' => 7, 'name' => 'Summer Party (check-in)', 'signing_key' => 'k', 'created_at' => now(), 'updated_at' => now()],
        ]);

        LocalTables::flush();
    }
}
