<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->json('options')->nullable();
            $table->string('answer', 500)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'answered_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_questions');
    }
};
