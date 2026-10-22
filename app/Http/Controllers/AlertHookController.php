<?php

namespace App\Http\Controllers;

use App\Models\HookToken;
use App\Office\Switchboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertHookController
{
    public function __invoke(Request $request, string $token, Switchboard $switchboard): JsonResponse
    {
        $hook = HookToken::findByPlain($token, HookToken::ALERT);

        abort_unless($hook, 404);

        $hook->touchUsed();

        $request->validate([
            'action' => ['required', Rule::in(Switchboard::ACTIONS)],
            'project' => 'required|array',
            'project.name' => 'required|string|max:200',
            'project.slug' => 'nullable|string|max:200',
            'issue' => 'required|array',
            'issue.id' => 'nullable',
            'issue.short_id' => 'nullable|string|max:80',
            'issue.level' => 'nullable|string|max:40',
            'issue.times_seen' => 'nullable|integer',
            'issue.url' => 'nullable|string|max:1000',
        ]);

        $alarm = $switchboard->alert($request->only(['action', 'project', 'issue']));

        return response()->json(['ok' => true, 'alarm' => $alarm->id]);
    }
}
