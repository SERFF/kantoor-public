<?php

namespace App\Http\Controllers;

use App\Office\ClaudeCodeHook;
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
            'tool_name' => 'nullable|string|max:200',
            'tool_input' => 'nullable|array',
            'message' => 'nullable|string|max:2000',
            'agent_id' => 'nullable|string|max:120',
            'agent_type' => 'nullable|string|max:120',
        ]);

        $agent = $hook->handle($request->only(['session_id', 'hook_event_name', 'cwd', 'tool_name', 'tool_input', 'message', 'agent_id', 'agent_type']));

        return response()->json(['ok' => true, 'agent' => $agent?->name]);
    }
}
