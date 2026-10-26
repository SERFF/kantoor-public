<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_limits', function (Blueprint $table) {
            $table->timestamp('five_hour_measured_at')->nullable()->after('five_hour_resets_estimated');
            $table->timestamp('seven_day_measured_at')->nullable()->after('seven_day_resets_estimated');
        });
    }

    public function down(): void
    {
        Schema::table('usage_limits', function (Blueprint $table) {
            $table->dropColumn(['five_hour_measured_at', 'seven_day_measured_at']);
        });
    }
};
