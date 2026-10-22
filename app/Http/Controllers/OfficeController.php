<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\Alarm;
use App\Models\Project;
use App\Models\UsageLimit;
use App\Office\Logbook;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfficeController
{
    public function index(): View
    {
        return view('office', ['config' => $this->config()]);
    }

    public function config(): array
    {
        return [
            'stateUrl' => route('office.state'),
            'sign' => mb_strtoupper(mb_substr((string) config('kantoor.sign'), 0, 10)),
            'dog' => (string) config('kantoor.dog'),
            'agentUrl' => url('/office/agents'),
            'questionUrl' => url('/office/questions'),
            'logbookUrl' => route('logbook'),
            'alarmUrl' => url('/office/alarms'),
            'portraits' => $this->image('portraits'),
            'characters' => $this->image('characters'),
            'props' => $this->image('props'),
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('kantoor.websocket.host') ?: parse_url(config('app.url'), PHP_URL_HOST),
                'port' => config('kantoor.websocket.port'),
                'scheme' => config('kantoor.websocket.scheme'),
            ],
        ];
    }

    protected function image(string $name): string
    {
        $path = public_path("img/{$name}.png");

        return asset("img/{$name}.png").(is_file($path) ? '?v='.filemtime($path) : '');
    }

    public function state(): JsonResponse
    {
        $logbook = app(Logbook::class);

        $agents = Agent::query()
            ->with('openQuestion')
            ->where(fn ($query) => $query
                ->where('status', '!=', Status::Offline->value)
                ->orWhere('status_changed_at', '>=', now()->subMinutes(10)))
            ->orderBy('id')
            ->get();

        $events = AgentEvent::query()
            ->with(['agent', 'toAgent'])
            ->latest('id')
            ->limit(40)
            ->get();

        return response()->json([
            'projects' => Project::query()->orderBy('position')->orderBy('id')->get()->map->toOffice(),
            'agents' => $agents->map->toOffice(),
            'events' => $events->map->toOffice(),
            'today' => $logbook->today(),
            'star' => $logbook->star(),
            'alarms' => Alarm::query()->with('project')->ringing()->latest('rang_at')->limit(10)->get()->map->toOffice(),
            'usage' => UsageLimit::current()?->toOffice(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function update(Request $request, Agent $agent, Office $office): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:40',
            'role' => 'sometimes|nullable|string|max:80',
            'project' => 'sometimes|nullable|string|max:60',
            'status' => ['sometimes', Rule::in([Status::Idle->value, Status::Offline->value])],
        ]);

        $agent->fill(array_intersect_key($data, array_flip(['name', 'role'])));

        if (array_key_exists('project', $data)) {
            $agent->project_id = Project::resolve($data['project'])?->id;
        }

        if ($agent->isDirty('name')) {
            $office->matchAvatar($agent);
        }

        $agent->save();

        if (isset($data['status'])) {
            $office->report($agent, Status::from($data['status']), $data['status'] === 'idle' ? 'Released by you' : null);
        } else {
            $office->broadcast($agent);
        }

        return response()->json(['agent' => $agent->fresh()->toOffice()]);
    }
}
