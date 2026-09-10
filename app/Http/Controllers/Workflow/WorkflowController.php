<?php

namespace App\Http\Controllers\Workflow;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\CompleteAttemptRequest;
use App\Http\Requests\Workflow\HumanActionRequest;
use App\Http\Requests\Workflow\ManualStageOverrideRequest;
use App\Http\Requests\Workflow\StartWorkflowRequest;
use App\Models\Project;
use App\Models\StageRun;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function index(Request $request): View
    {
        $runs = WorkflowRun::query()
            ->whereBelongsTo($request->user())
            ->with(['definition', 'currentStage', 'activeStageRun'])
            ->latest()
            ->paginate(12);

        $counts = WorkflowRun::query()
            ->whereBelongsTo($request->user())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('workflows.index', compact('runs', 'counts'));
    }

    public function create(Request $request, ?Project $project = null): View|RedirectResponse
    {
        if ($project) {
            abort_unless($project->user_id === $request->user()->id, 404);
            $project->load('currentConnection');
        } else {
            $projects = Project::query()->whereBelongsTo($request->user())->orderBy('name')->get();
            if ($projects->count() === 1) {
                return to_route('projects.workflows.create', $projects->first());
            }
            if ($projects->isEmpty()) {
                return to_route('projects.index')->with('status', 'Create a project before starting a workflow.');
            }

            return view('workflows.choose-project', compact('projects'));
        }
        $controllerProtocol = $project->currentConnection?->controller_protocol_version ?? 1;
        $supportsMerge = ! config('services.amp.enabled')
            || $controllerProtocol >= 2;
        $supportsExplanation = ! config('services.amp.enabled') || $controllerProtocol >= 3;
        $definitions = WorkflowDefinition::query()
            ->where('is_active', true)
            ->when(! $supportsMerge, fn ($query) => $query->where('version', '<', 4))
            ->when($supportsMerge && ! $supportsExplanation, fn ($query) => $query->where('version', '<', 5))
            ->with('stages')
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        return view('workflows.create', compact('definitions', 'project', 'supportsMerge', 'supportsExplanation'));
    }

    public function store(StartWorkflowRequest $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);
        $definition = WorkflowDefinition::query()
            ->where('is_active', true)
            ->findOrFail($request->integer('workflow_definition_id'));
        $issueNumber = $request->integer('github_issue_number');
        $issueUrl = sprintf('https://github.com/%s/issues/%d', $project->github_repository, $issueNumber);

        try {
            $run = $this->engine->start(
                $request->user(),
                $definition,
                $project,
                $issueNumber,
                $issueUrl,
            );
        } catch (WorkflowConflict $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return to_route('workflows.show', $run)->with('status', 'Workflow started on the project’s verified controller connection.');
    }

    public function show(Request $request, WorkflowRun $workflowRun): View
    {
        $this->assertOwner($request, $workflowRun);

        $workflowRun->load([
            'definition.stages',
            'definition.transitions.toStage',
            'currentStage.outgoingTransitions',
            'activeStageRun.stage.outgoingTransitions',
            'activeStageRun.ampLaunch',
            'project',
            'stageRuns.stage',
            'stageRuns.ampLaunch',
            'stageInstructions',
            'events.stageRun.stage',
        ]);

        return view('workflows.show', ['run' => $workflowRun]);
    }

    public function simulate(
        CompleteAttemptRequest $request,
        WorkflowRun $workflowRun,
        StageRun $stageRun,
    ): RedirectResponse {
        $this->assertOwner($request, $workflowRun);

        try {
            $this->engine->simulateAgentCompletion(
                $workflowRun,
                $stageRun,
                $request->string('outcome')->toString(),
                $request->user(),
            );
        } catch (WorkflowConflict $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Simulated agent completion recorded.');
    }

    public function humanAction(
        HumanActionRequest $request,
        WorkflowRun $workflowRun,
        StageRun $stageRun,
    ): RedirectResponse {
        $this->assertOwner($request, $workflowRun);

        try {
            $this->engine->completeHumanAction(
                $workflowRun,
                $stageRun,
                $request->string('outcome')->toString(),
                $request->user(),
            );
        } catch (WorkflowConflict $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Review decision recorded.');
    }

    public function pause(
        Request $request,
        WorkflowRun $workflowRun,
        StageRun $stageRun,
    ): RedirectResponse {
        $this->assertOwner($request, $workflowRun);

        try {
            $this->engine->pause($workflowRun, $stageRun, $request->user());
        } catch (WorkflowConflict $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Current attempt stopped. Choose a stage when you are ready to resume.');
    }

    public function overrideStage(
        ManualStageOverrideRequest $request,
        WorkflowRun $workflowRun,
        StageRun $stageRun,
    ): RedirectResponse {
        $this->assertOwner($request, $workflowRun);

        try {
            $run = $this->engine->overrideStage(
                $workflowRun,
                $stageRun,
                $request->integer('target_stage_id'),
                $request->user(),
            );
        } catch (WorkflowConflict $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Workflow moved to '.$run->currentStage->name.' with a new audited attempt.');
    }

    public function cancel(Request $request, WorkflowRun $workflowRun): RedirectResponse
    {
        $this->assertOwner($request, $workflowRun);

        try {
            $this->engine->cancel($workflowRun, $request->user());
        } catch (WorkflowConflict $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Workflow cancelled.');
    }

    private function assertOwner(Request $request, WorkflowRun $run): void
    {
        abort_unless($run->user_id === $request->user()->getKey(), 404);
    }
}
