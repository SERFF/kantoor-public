<?php

use App\Models\Agent;
use App\Office\Office;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $office = app(Office::class);

        Agent::query()->orderBy('id')->get()->each(function (Agent $agent) use ($office) {
            $office->matchAvatar($agent);
            $agent->save();
        });
    }
};
