<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_sync_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_mapping_id')->constrained('connect_sync_mappings')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('connect_sync_runs')->nullOnDelete();
            $table->string('remote_id');
            $table->text('reason');
            $table->json('payload');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['sync_mapping_id', 'remote_id'], 'connect_sync_issues_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_sync_issues');
    }
};
