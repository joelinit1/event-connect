<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_attendee_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('table_name');
            $table->string('filter_column')->nullable();
            $table->string('filter_value')->nullable();
            $table->string('id_column')->default('id');
            $table->string('updated_at_column')->nullable();
            $table->string('deleted_at_column')->nullable();
            $table->json('field_map')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_attendee_sources');
    }
};
