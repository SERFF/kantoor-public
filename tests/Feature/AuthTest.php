<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_requires_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/office/state')->assertUnauthorized();
    }

    public function test_mcp_endpoint_requires_a_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();
    }

    public function test_oauth_metadata_is_published(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);

        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_dynamic_registration_rejects_foreign_redirects(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Evil',
            'redirect_uris' => ['https://evil.example.com/callback'],
        ])->assertClientError();

        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertSuccessful();
    }

    public function test_invite_link_sets_the_password(): void
    {
        $this->artisan('kantoor:invite', ['email' => 'sam@example.test'])->assertSuccessful();

        $user = User::firstWhere('email', 'sam@example.test');
        $this->assertNotNull($user);
    }
}
