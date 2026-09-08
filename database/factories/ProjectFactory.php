<?php

namespace Database\Factories;

use App\Models\AmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $repository = fake()->unique()->slug(2);

        return [
            'user_id' => User::factory()->state(['can_trigger_amp' => true]),
            'name' => fake()->words(2, true),
            'github_repository' => 'acme/'.$repository,
            'amp_project_id' => (string) Str::uuid(),
        ];
    }

    public function configured(string $webhook = 'https://ampcode.com/api/webhooks/test-capability'): static
    {
        return $this->afterCreating(function (Project $project) use ($webhook) {
            $connection = AmpProjectConnection::query()->create([
                'project_id' => $project->id,
                'public_id' => (string) Str::uuid(),
                'version' => 1,
                'amp_project_id' => $project->amp_project_id,
                'controller_key' => 'orc-stage-launch-v8',
                'launch_webhook_url' => $webhook,
                'launch_signing_secret' => 'launch-test-secret-that-is-at-least-32-bytes',
                'callback_signing_secret' => 'callback-test-secret-that-is-at-least-32-bytes',
                'status' => 'verified',
                'verified_at' => now(),
            ]);
            $project->forceFill(['current_amp_project_connection_id' => $connection->id])->save();
        });
    }
}
