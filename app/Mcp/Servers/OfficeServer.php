<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\DaySummary;
use App\Mcp\Tools\GetAnswer;
use App\Mcp\Tools\HandOff;
use App\Mcp\Tools\OfficeOverview;
use App\Mcp\Tools\ReportStatus;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Kantoor')]
#[Version('1.3.0')]
class OfficeServer extends Server
{
    protected array $tools = [
        ReportStatus::class,
        HandOff::class,
        GetAnswer::class,
        OfficeOverview::class,
        DaySummary::class,
    ];

    protected function boot(): void
    {
        $this->instructions = implode("\n\n", [
            'Kantoor shows the user\'s Claude agents (skills, scheduled tasks, cloud sessions) as colleagues in a live virtual office.',
            'When you run a skill or a scheduled task, report to the office: call report_status with status "working" when you start, "waiting" when you need the user (drafts ready to review, a question, an approval), "done" with a one-line result when you finish, and "error" with the reason when you get stuck. Use the skill or task name as "agent" and keep it identical on every call, so the same colleague is updated.',
            'When you need a decision or answer from the user, add "question" (and optionally "options") to the "waiting" report. The user can answer in the office app. Before you continue, call get_answer for that agent: in a running session wait with wait_seconds, in a chat check it at the start of the next user message. If get_answer returns an answer, use it and carry on; if the user answered in the conversation instead, that answer counts.',
            'When your work is meant to continue in another skill or task (for example findings passed on to a writing skill), call hand_off with both agent names and a few words about what is passed on.',
            'When you start subagents (for example with the Agent tool), report each one as its own colleague, because a subagent usually cannot reach the office itself: call hand_off from your agent to the subagent and report_status "working" for it when it starts, and report_status "done" or "error" for it plus hand_off back when it returns. Give every subagent its own "agent" value, also when several run in parallel with the same role: "<your agent>-builder-1", "<your agent>-builder-2". Names ending in -researcher, -builder, -reviewer or -tester (optionally with a number) are placed next to the agent whose name comes before it; for other names pass "parent" to report_status.',
            'For an end-of-day recap or "what got done", call day_summary: it lists what was delivered per project and what is still open.',
            'Messages are shown on a wall screen: keep them short, in the user\'s language, and never include personal data, email addresses, credentials or customer content.',
            'Reporting is best-effort: if a call fails, carry on with the actual task.',
        ]);
    }
}
