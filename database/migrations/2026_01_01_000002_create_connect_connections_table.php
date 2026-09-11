<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('driver')->default('suite');
            $table->string('base_url');
            $table->text('token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_connections');
    }
};
