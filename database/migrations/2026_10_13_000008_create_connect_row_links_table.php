<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_row_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_mapping_id')->constrained('connect_sync_mappings')->cascadeOnDelete();
            $table->string('remote_id');
            $table->string('local_key');
            $table->timestamps();

            $table->unique(['sync_mapping_id', 'remote_id'], 'connect_row_links_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_row_links');
    }
};
