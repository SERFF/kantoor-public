<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_days', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('project_id')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('prompts')->default(0);
            $table->unsignedInteger('edits')->default(0);
            $table->unsignedInteger('lines_added')->default(0);
            $table->unsignedInteger('lines_removed')->default(0);
            $table->unsignedInteger('approvals')->default(0);
            $table->timestamps();

            $table->unique(['date', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_days');
    }
};
