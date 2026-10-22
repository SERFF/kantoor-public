<?php

namespace App\Http\Controllers;

use App\Models\LogEntry;
use App\Models\Project;
use App\Office\Logbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogbookController
{
    public const PERIODS = [7 => 'Week', 30 => 'Maand', 90 => 'Kwartaal', 365 => 'Jaar'];

    public function __invoke(Request $request, Logbook $logbook): View
    {
        $days = (int) $request->query('dagen', 30);
        $days = array_key_exists($days, self::PERIODS) ? $days : 30;
        $project = $request->filled('project') ? Project::query()->firstWhere('slug', $request->query('project')) : null;

        $until = $logbook->day()->addDay();
        $entries = $logbook->entries($until->subDays($days), $until, $project);
        $tz = $logbook->timezone();

        $projects = Project::query()
            ->whereIn('id', LogEntry::query()->whereNotNull('project_id')->select('project_id'))
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return view('logbook', [
            'project' => $project,
            'projects' => $projects,
            'days' => $days,
            'periods' => self::PERIODS,
            'total' => $entries->count(),
            'groups' => $entries->groupBy(fn (LogEntry $entry) => $entry->created_at->setTimezone($tz)->toDateString())
                ->map(fn ($group, $date) => [
                    'label' => ucfirst(CarbonImmutable::parse($date, $tz)->locale('nl')->isoFormat('dddd D MMMM')),
                    'entries' => $group->map(fn (LogEntry $entry) => [
                        'time' => $entry->created_at->setTimezone($tz)->format('H:i'),
                        'agent' => $entry->agent_name,
                        'role' => $entry->agent_role,
                        'avatar' => $entry->avatar,
                        'project' => $entry->project?->name,
                        'message' => $entry->message,
                    ]),
                ]),
            'portraits' => app(OfficeController::class)->config()['portraits'],
        ]);
    }
}
