<?php

namespace App\Mcp\Tools;

use App\Office\WeekRecap;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('week_summary')]
#[Description('Weekly recap of the office: deliveries, Claude Code sessions, prompts, files edited with lines added and removed, approvals given in the office, the busiest day and the totals per project. Use it for "how was my week" or a Monday recap.')]
#[IsReadOnly]
class WeekSummary extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'week' => $schema->string()->description('"current" (default), "previous", or any day in the week as YYYY-MM-DD.'),
        ];
    }

    public function handle(Request $request, WeekRecap $recap): Response
    {
        $request->validate(['week' => 'nullable|string|max:20']);

        $week = (string) ($request->get('week') ?? 'current');

        $date = match (true) {
            $week === 'current' => null,
            $week === 'previous' => CarbonImmutable::now(config('kantoor.timezone'))->subWeek()->toDateString(),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) => $week,
            default => false,
        };

        if ($date === false) {
            return Response::error('Use "current", "previous" or a date as YYYY-MM-DD.');
        }

        return Response::text($recap->summary($date));
    }
}
