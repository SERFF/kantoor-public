<?php

namespace App\Http\Controllers;

use App\Models\AgentQuestion;
use App\Office\HookRequests;
use App\Office\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController
{
    public function answer(Request $request, AgentQuestion $question, Office $office): JsonResponse
    {
        $data = $request->validate(['answer' => 'required|string|max:500']);

        abort_unless($question->isOpen(), 409, 'This question has already been answered or has expired.');

        if ($question->kind) {
            app(HookRequests::class)->answer($question, $data['answer']);
        } else {
            $office->answer($question, $data['answer']);
        }

        return response()->json(['agent' => $question->agent->fresh()->toOffice()]);
    }
}
