<?php

namespace App\Http\Controllers;

use App\Events\UsageChanged;
use App\Models\UsageLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageHookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rate_limits' => 'required|array',
            'rate_limits.five_hour' => 'nullable|array',
            'rate_limits.five_hour.used_percentage' => 'nullable|numeric|min:0|max:1000',
            'rate_limits.five_hour.resets_at' => 'nullable',
            'rate_limits.seven_day' => 'nullable|array',
            'rate_limits.seven_day.used_percentage' => 'nullable|numeric|min:0|max:1000',
            'rate_limits.seven_day.resets_at' => 'nullable',
        ]);

        [$limit, $changed] = UsageLimit::record($data['rate_limits']);

        if ($changed) {
            UsageChanged::dispatch($limit);
        }

        return response()->json(['ok' => true, 'usage' => $limit->toOffice()]);
    }
}
