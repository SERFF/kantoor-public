<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_questions', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('agent_id');
            $table->string('request', 40)->nullable()->after('kind')->index();
            $table->json('payload')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('agent_questions', function (Blueprint $table) {
            $table->dropIndex(['request']);
            $table->dropColumn(['kind', 'request', 'payload']);
        });
    }
};
