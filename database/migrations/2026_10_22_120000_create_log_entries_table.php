<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_name', 40);
            $table->string('agent_role', 80)->nullable();
            $table->unsignedTinyInteger('avatar')->default(0);
            $table->string('message')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        DB::table('agent_events')
            ->join('agents', 'agents.id', '=', 'agent_events.agent_id')
            ->where('agent_events.type', 'status')
            ->where('agent_events.status', 'done')
            ->orderBy('agent_events.id')
            ->select('agents.project_id', 'agents.id as agent_id', 'agents.name', 'agents.role', 'agents.avatar', 'agent_events.message', 'agent_events.created_at')
            ->get()
            ->chunk(200)
            ->each(fn ($rows) => DB::table('log_entries')->insert($rows->map(fn ($row) => [
                'project_id' => $row->project_id,
                'agent_id' => $row->agent_id,
                'agent_name' => $row->name,
                'agent_role' => $row->role,
                'avatar' => $row->avatar,
                'message' => $row->message,
                'created_at' => $row->created_at,
            ])->all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
