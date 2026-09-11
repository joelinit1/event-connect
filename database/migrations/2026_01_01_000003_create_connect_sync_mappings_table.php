<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('connect_connections')->cascadeOnDelete();
            $table->string('remote_event_id');
            $table->string('remote_event_name')->nullable();
            $table->unsignedBigInteger('local_event_id')->index();
            $table->boolean('enabled')->default(true);
            $table->timestamp('cursor')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'remote_event_id', 'local_event_id'], 'connect_sync_mappings_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_sync_mappings');
    }
};
