<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_limits', function (Blueprint $table) {
            $table->boolean('five_hour_resets_estimated')->default(false)->after('five_hour_resets_at');
            $table->boolean('seven_day_resets_estimated')->default(false)->after('seven_day_resets_at');
            $table->timestamp('measured_at')->nullable()->after('seven_day_resets_estimated');
            $table->string('source', 20)->nullable()->after('measured_at');
        });
    }

    public function down(): void
    {
        Schema::table('usage_limits', function (Blueprint $table) {
            $table->dropColumn(['five_hour_resets_estimated', 'seven_day_resets_estimated', 'measured_at', 'source']);
        });
    }
};
