<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('match')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('source', 20)->default('mcp');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->unsignedTinyInteger('avatar')->default(0);
            $table->string('status', 20)->default('idle');
            $table->string('message')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('timeout_minutes')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('to_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->nullable();
            $table->string('message')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('hook_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hook_tokens');
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('projects');
    }
};
