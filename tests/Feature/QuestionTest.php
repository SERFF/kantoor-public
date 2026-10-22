<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Mcp\Servers\OfficeServer;
use App\Mcp\Tools\GetAnswer;
use App\Mcp\Tools\ReportStatus;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\User;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_a_waiting_report_can_ask_a_question_with_options(): void
    {
        OfficeServer::tool(ReportStatus::class, [
            'agent' => 'webshop',
            'status' => 'waiting',
            'question' => 'Welke branch?',
            'options' => ['main', 'develop', 'main'],
        ])->assertOk()->assertSee('get_answer');

        $agent = Agent::firstWhere('key', 'mcp:webshop');
        $this->assertSame(Status::Waiting, $agent->status);
        $this->assertSame(['main', 'develop'], $agent->openQuestion->options);

        $this->actingAs(User::factory()->create());
        $this->getJson('/office/state')
            ->assertOk()
            ->assertJsonPath('agents.0.question.question', 'Welke branch?');
    }

    public function test_options_sent_as_text_are_accepted(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'a', 'status' => 'waiting', 'question' => 'Welke?', 'options' => '["Ja", "Nee"]'])->assertOk();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'b', 'status' => 'waiting', 'question' => 'Welke?', 'options' => 'Ja | Nee'])->assertOk();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'c', 'status' => 'waiting', 'question' => 'Welke?', 'options' => range(1, 7)])->assertHasErrors();

        $this->assertSame([['Ja', 'Nee'], ['Ja', 'Nee']], AgentQuestion::query()->orderBy('id')->pluck('options')->all());
    }

    public function test_a_question_needs_status_waiting(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'question' => 'Welke branch?'])
            ->assertHasErrors();
    }

    public function test_answer_in_the_app_is_collected_once(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Welke branch?']);
        $question = AgentQuestion::first();

        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop'])->assertOk()->assertSee('nog niet beantwoord');

        $this->actingAs(User::factory()->create());
        $this->postJson("/office/questions/{$question->id}/answer", ['answer' => 'develop'])
            ->assertOk()
            ->assertJsonPath('agent.status', 'idle')
            ->assertJsonPath('agent.question', null);

        $this->postJson("/office/questions/{$question->id}/answer", ['answer' => 'main'])->assertStatus(409);

        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop'])->assertOk()->assertSee('develop');
        $this->assertSame(Status::Working, Agent::first()->status);

        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop'])->assertOk()->assertSee('geen vraag open');
    }

    public function test_moving_on_cancels_the_open_question(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Welke branch?']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'message' => 'Antwoord in de chat gekregen']);

        $this->assertNull(Agent::first()->openQuestion);
        $this->assertNotNull(AgentQuestion::first()->cancelled_at);

        $this->actingAs(User::factory()->create());
        $this->postJson('/office/questions/'.AgentQuestion::first()->id.'/answer', ['answer' => 'develop'])->assertStatus(409);
    }

    public function test_a_new_question_replaces_the_old_one(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Eerste?']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Tweede?']);

        $this->assertSame('Tweede?', Agent::first()->openQuestion->question);
        $this->assertSame(1, AgentQuestion::query()->open()->count());
    }

    public function test_a_new_question_discards_an_old_answer_that_was_never_collected(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Oud?']);
        app(Office::class)->answer(Agent::first()->openQuestion, 'Oké');

        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Nieuw?']);
        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop'])->assertOk()->assertSee('nog niet beantwoord')->assertDontSee('Oké');

        $agent = Agent::first();
        $this->assertSame(Status::Waiting, $agent->status);
        $this->assertSame('Nieuw?', $agent->openQuestion->question);
    }

    public function test_a_waiting_report_without_question_asks_its_message(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'recikeep', 'status' => 'waiting', 'message' => 'Bestaande lijsten rechtzetten?'])
            ->assertOk()
            ->assertSee('get_answer');

        $this->assertSame('Bestaande lijsten rechtzetten?', Agent::first()->openQuestion->question);
    }

    public function test_a_waiting_report_without_question_keeps_the_open_one(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Welke branch?', 'options' => ['main', 'develop']]);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'message' => 'Wacht nog steeds']);

        $this->assertSame('Welke branch?', Agent::first()->openQuestion->question);
        $this->assertSame(1, AgentQuestion::query()->open()->count());
    }

    public function test_answering_requires_login(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Welke branch?']);

        $this->postJson('/office/questions/'.AgentQuestion::first()->id.'/answer', ['answer' => 'develop'])->assertUnauthorized();
    }

    public function test_old_questions_are_pruned(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Welke branch?']);
        AgentQuestion::query()->update(['updated_at' => now()->subDays(8)]);

        $this->artisan('kantoor:check')->assertSuccessful();

        $this->assertSame(0, AgentQuestion::count());
    }
}
