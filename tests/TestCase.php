<?php

namespace Tests;

use App\Models\AmpProjectConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function workflowProject(User $user, string $repository): Project
    {
        $project = Project::query()->firstOrCreate(
            ['user_id' => $user->id, 'github_repository' => strtolower($repository)],
            ['name' => Str::headline(Str::afterLast($repository, '/')), 'amp_project_id' => 'amp-project-test'],
        );

        if (config('services.amp.enabled') && ! $project->current_amp_project_connection_id) {
            $url = (string) (config('services.amp.launch_webhook_url') ?: 'https://amp.test/webhook');
            $host = (string) parse_url($url, PHP_URL_HOST);
            config(['services.amp.webhook_allowed_hosts' => [$host]]);
            $connection = AmpProjectConnection::query()->create([
                'project_id' => $project->id,
                'public_id' => (string) Str::uuid(),
                'version' => 1,
                'amp_project_id' => $project->amp_project_id,
                'controller_key' => 'orc-stage-launch-v8',
                'launch_webhook_url' => $url,
                'launch_signing_secret' => (string) (config('services.amp.launch_signing_secret') ?: str_repeat('l', 32)),
                'callback_signing_secret' => (string) (config('services.amp.callback_signing_secret') ?: str_repeat('c', 32)),
                'status' => 'verified',
                'verified_at' => now(),
            ]);
            $project->update(['current_amp_project_connection_id' => $connection->id]);
        }

        return $project->fresh('currentConnection');
    }
}
