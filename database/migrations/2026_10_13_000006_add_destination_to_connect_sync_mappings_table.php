<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connect_sync_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('local_event_id')->nullable()->change();
            $table->string('target_table')->nullable()->after('local_event_id');
            $table->string('target_key_column')->nullable()->after('target_table');
            $table->string('target_event_column')->nullable()->after('target_key_column');
            $table->string('on_delete')->default('delete')->after('target_event_column');
            $table->json('field_map')->nullable()->after('on_delete');
            $table->json('seen_fields')->nullable()->after('field_map');
        });

        Schema::table('connect_sync_runs', function (Blueprint $table) {
            $table->unsignedInteger('row_count')->default(0)->after('skipped');
            $table->json('coverage')->nullable()->after('row_count');
            $table->json('warnings')->nullable()->after('errors');
        });
    }

    public function down(): void
    {
        Schema::table('connect_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['row_count', 'coverage', 'warnings']);
        });

        Schema::table('connect_sync_mappings', function (Blueprint $table) {
            $table->dropColumn(['target_table', 'target_key_column', 'target_event_column', 'on_delete', 'field_map', 'seen_fields']);
        });
    }
};
