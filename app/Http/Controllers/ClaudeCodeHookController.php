<?php

namespace App\Http\Controllers;

use App\Office\ClaudeCodeHook;
use App\Office\HookRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaudeCodeHookController
{
    public function __invoke(Request $request, ClaudeCodeHook $hook): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string|max:120',
            'hook_event_name' => 'required|string|max:60',
            'cwd' => 'nullable|string|max:1000',
            'source' => 'nullable|string|max:30',
            'tool_name' => 'nullable|string|max:200',
            'tool_input' => 'nullable|array',
            'message' => 'nullable|string|max:2000',
            'agent_id' => 'nullable|string|max:120',
            'agent_type' => 'nullable|string|max:120',
            'permission_suggestions' => 'nullable|array',
            'lines' => 'nullable|array',
            'lines.added' => 'nullable|integer|min:0',
            'lines.removed' => 'nullable|integer|min:0',
            'wait' => 'nullable|boolean',
        ]);

        $agent = $hook->handle($request->only([
            'session_id', 'hook_event_name', 'cwd', 'source', 'tool_name', 'tool_input', 'message',
            'agent_id', 'agent_type', 'permission_suggestions', 'lines', 'wait',
        ]));

        return response()->json(array_filter([
            'ok' => true,
            'agent' => $agent?->name,
            'request' => $hook->request,
        ], fn ($value) => $value !== null));
    }

    public function show(string $request, HookRequests $requests): JsonResponse
    {
        return response()->json($requests->status($request));
    }

    public function destroy(string $request, HookRequests $requests): JsonResponse
    {
        $requests->cancel($request);

        return response()->json(['ok' => true]);
    }
}
