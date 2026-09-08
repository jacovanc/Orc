<?php

namespace App\Http\Controllers;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Models\Project;
use App\Services\AmpProjectConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private readonly AmpProjectConnectionService $connections) {}

    public function index(Request $request): View
    {
        $projects = Project::query()
            ->whereBelongsTo($request->user())
            ->with('currentConnection')
            ->withCount(['workflowRuns', 'workflowRuns as running_workflow_runs_count' => fn ($query) => $query->where('status', 'running')])
            ->orderBy('name')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can_trigger_amp, 403);
        $request->merge([
            'github_repository' => strtolower(trim((string) $request->input('github_repository'), ' /')),
            'amp_project_id' => trim((string) $request->input('amp_project_id')),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'github_repository' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/',
                Rule::unique('projects')->where('user_id', $request->user()->id),
            ],
            'amp_project_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $project = DB::transaction(function () use ($request, $data) {
            $project = $request->user()->projects()->create($data);
            $this->connections->issueSetup($project, $request->user());

            return $project;
        }, 3);

        return to_route('projects.settings', $project)->with('status', 'Project created. Copy its setup prompt to an agent in the matching Amp project.');
    }

    public function show(Request $request, Project $project): View
    {
        $this->assertOwner($request, $project);
        $runs = $project->workflowRuns()
            ->with(['definition', 'currentStage', 'activeStageRun'])
            ->latest()
            ->paginate(12);
        $project->load('currentConnection');

        return view('projects.show', compact('project', 'runs'));
    }

    public function settings(Request $request, Project $project): View
    {
        $this->assertOwner($request, $project);
        $project->load(['currentConnection.setups', 'connections']);
        $setup = $project->currentConnection?->setups->sortByDesc('id')->first();
        $setupPrompt = $setup && in_array($setup->status, ['pending', 'claimed'], true) && ! $setup->isExpired()
            ? $this->connections->setupPrompt($setup)
            : null;

        return view('projects.settings', compact('project', 'setup', 'setupPrompt'));
    }

    public function issueSetup(Request $request, Project $project): RedirectResponse
    {
        $this->assertOwner($request, $project);

        try {
            $this->connections->issueSetup($project, $request->user());
        } catch (WorkflowConflict $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('status', 'A new setup prompt was generated. Any previous unused prompt is revoked.');
    }

    public function configure(Request $request, Project $project): RedirectResponse
    {
        $this->assertOwner($request, $project);
        $data = $request->validate([
            'amp_project_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'launch_webhook_url' => ['required', 'url:https', 'max:2048'],
            'launch_signing_secret' => ['required', 'string', 'min:32', 'max:500'],
            'callback_signing_secret' => ['required', 'string', 'min:32', 'max:500', 'different:launch_signing_secret'],
        ]);

        try {
            $this->connections->configure($project, $request->user(), $data);
        } catch (WorkflowConflict $exception) {
            return back()->withInput($request->except(['launch_signing_secret', 'callback_signing_secret']))
                ->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('status', 'A new immutable connection version was saved. Reload the controller plugin with these exact settings, then verify it.');
    }

    public function verify(Request $request, Project $project): RedirectResponse
    {
        $this->assertOwner($request, $project);
        $connection = $project->currentConnection()->firstOrFail();

        try {
            $this->connections->beginVerification($project, $connection, $request->user());
        } catch (WorkflowConflict $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('status', 'Verification queued. Refresh shortly to see the fresh-Orb placement and native GitHub access result.');
    }

    private function assertOwner(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
