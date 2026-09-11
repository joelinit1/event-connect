<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_mapping_id')->constrained('connect_sync_mappings')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('deleted')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_sync_runs');
    }
};
