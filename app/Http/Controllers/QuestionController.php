<?php

namespace App\Http\Controllers;

use App\Models\AgentQuestion;
use App\Office\Office;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController
{
    public function answer(Request $request, AgentQuestion $question, Office $office): JsonResponse
    {
        $data = $request->validate(['answer' => 'required|string|max:500']);

        abort_unless($question->isOpen(), 409, 'Deze vraag is al beantwoord of vervallen.');

        $office->answer($question, $data['answer']);

        return response()->json(['agent' => $question->agent->fresh()->toOffice()]);
    }
}
