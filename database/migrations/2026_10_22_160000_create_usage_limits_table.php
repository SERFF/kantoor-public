<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_limits', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->decimal('five_hour_used', 5, 2)->nullable();
            $table->timestamp('five_hour_resets_at')->nullable();
            $table->decimal('seven_day_used', 5, 2)->nullable();
            $table->timestamp('seven_day_resets_at')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_limits');
    }
};
