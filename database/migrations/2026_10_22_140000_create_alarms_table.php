<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hook_tokens', function (Blueprint $table) {
            $table->string('kind', 20)->default('claude_code')->after('name');
        });

        Schema::create('alarms', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('issue_key', 80);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name', 80);
            $table->string('short_id', 40)->nullable();
            $table->string('level', 20)->nullable();
            $table->string('action', 20);
            $table->unsignedInteger('times_seen')->default(1);
            $table->string('url', 500)->nullable();
            $table->timestamp('rang_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'issue_key']);
            $table->index('rang_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarms');

        Schema::table('hook_tokens', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
