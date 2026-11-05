<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Project;
use App\Office\History;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController
{
    public function agent(Request $request, Agent $agent, History $history): JsonResponse
    {
        return response()->json($history->forAgent($agent, $this->before($request)));
    }

    public function project(Request $request, string $project, History $history): JsonResponse
    {
        $model = $project === 'none' ? null : Project::query()->findOrFail($project);

        return response()->json($history->forProject($model, $this->before($request)));
    }

    protected function before(Request $request): ?CarbonImmutable
    {
        $request->validate(['before' => 'nullable|date']);

        return $request->filled('before') ? CarbonImmutable::parse($request->query('before')) : null;
    }
}
