<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('measured_at')->unique();
            $table->float('five_hour_used')->nullable();
            $table->float('seven_day_used')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_samples');
    }
};
