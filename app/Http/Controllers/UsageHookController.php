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
            'source' => 'nullable|string|in:statusline,desktop',
            'measured_at' => 'nullable|integer|min:0',
            'rate_limits' => 'required|array',
            'rate_limits.five_hour' => 'nullable|array',
            'rate_limits.five_hour.used_percentage' => 'nullable|numeric|min:0|max:1000',
            'rate_limits.five_hour.resets_at' => 'nullable',
            'rate_limits.five_hour.resets_estimated' => 'nullable|boolean',
            'rate_limits.seven_day' => 'nullable|array',
            'rate_limits.seven_day.used_percentage' => 'nullable|numeric|min:0|max:1000',
            'rate_limits.seven_day.resets_at' => 'nullable',
            'rate_limits.seven_day.resets_estimated' => 'nullable|boolean',
            'history' => 'nullable|array|max:2000',
            'history.*.measured_at' => 'required|integer|min:0',
            'history.*.five_hour' => 'nullable|numeric|min:0|max:1000',
            'history.*.seven_day' => 'nullable|numeric|min:0|max:1000',
        ]);

        UsageLimit::rememberHistory($data['history'] ?? []);

        [$limit, $changed] = UsageLimit::record($data['rate_limits'], $data['measured_at'] ?? null, $data['source'] ?? null);

        if ($changed) {
            UsageChanged::dispatch($limit);
        }

        return response()->json(['ok' => true, 'usage' => $limit->toOffice()]);
    }
}
