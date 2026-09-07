<?php

namespace App\Http\Controllers\Workflow;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\CompleteAttemptRequest;
use App\Http\Requests\Workflow\HumanActionRequest;
use App\Http\Requests\Workflow\StartWorkflowRequest;
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

    public function create(): View
    {
        $definitions = WorkflowDefinition::query()
            ->where('is_active', true)
            ->with('stages')
            ->orderBy('name')
            ->orderByDesc('version')
            ->get();

        return view('workflows.create', compact('definitions'));
    }

    public function store(StartWorkflowRequest $request): RedirectResponse
    {
        $definition = WorkflowDefinition::query()
            ->where('is_active', true)
            ->findOrFail($request->integer('workflow_definition_id'));

        try {
            $run = $this->engine->start(
                $request->user(),
                $definition,
                $request->string('github_repository')->toString(),
                $request->integer('github_issue_number'),
                $request->string('github_issue_url')->toString(),
            );
        } catch (WorkflowConflict $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return to_route('workflows.show', $run)->with('status', 'Workflow started.');
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
            'stageRuns.stage',
            'stageRuns.ampLaunch',
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
                $request->input('github_feedback_url'),
            );
        } catch (WorkflowConflict $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return back()->with('status', 'Review decision recorded.');
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
