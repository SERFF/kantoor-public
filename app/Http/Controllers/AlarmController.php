<?php

namespace App\Http\Controllers;

use App\Models\Alarm;
use App\Office\Switchboard;
use Illuminate\Http\JsonResponse;

class AlarmController
{
    public function answer(Switchboard $switchboard, ?Alarm $alarm = null): JsonResponse
    {
        $switchboard->answer($alarm);

        return response()->json(['ok' => true]);
    }
}
