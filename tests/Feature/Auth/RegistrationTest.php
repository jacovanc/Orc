<?php

namespace Tests\Feature\Auth;

use App\Jobs\DeliverAmpLaunch;
use App\Models\Project;
use App\Models\WorkflowDefinition;
use Database\Seeders\DevelopmentWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        config(['auth.registration_enabled' => true]);

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        config(['auth.registration_enabled' => true]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_disabled_by_default(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_newly_registered_user_cannot_trigger_amp_work_without_operator_allowlist(): void
    {
        config(['auth.registration_enabled' => true]);
        $this->post('/register', [
            'name' => 'Unapproved User',
            'email' => 'unapproved@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->seed(DevelopmentWorkflowSeeder::class);
        config(['services.amp.enabled' => true]);
        Queue::fake();

        $project = Project::query()->create([
            'user_id' => auth()->id(),
            'name' => 'Widgets',
            'github_repository' => 'acme/widgets',
            'amp_project_id' => 'amp-project-test',
        ]);
        $this->from(route('projects.workflows.create', $project))->post(route('projects.workflows.store', $project), [
            'workflow_definition_id' => WorkflowDefinition::query()->where('version', 2)->sole()->id,
            'github_issue_number' => 42,
        ])->assertRedirect(route('projects.workflows.create', $project))
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('workflow_runs', 0);
        $this->assertDatabaseCount('amp_launches', 0);
        Queue::assertNotPushed(DeliverAmpLaunch::class);
    }
}
