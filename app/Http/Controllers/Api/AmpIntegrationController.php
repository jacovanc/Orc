<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Http\Controllers\Controller;
use App\Models\AmpProjectConnection;
use App\Services\AmpProjectConnectionService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AmpIntegrationController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly AmpProjectConnectionService $connections,
    ) {}

    public function callback(Request $request, AmpProjectConnection $ampProjectConnection): JsonResponse
    {
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in([
                'launch.claim',
                'launch.acknowledged',
                'launch.ambiguous',
                'stage.report_claimed',
                'stage.reported',
                'stage.published',
                'stage.merge_verified',
                'stage.completed',
                'stage.failed',
            ])],
            'occurred_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'launch_event_id' => ['required', 'uuid'],
            'stage_run_id' => ['required', 'integer', 'min:1'],
            'thread_id' => ['nullable', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'outcome' => ['nullable', 'string', 'max:64'],
            'github_report_url' => ['nullable', 'url:https', 'max:2048'],
            'github_report_comment_id' => ['nullable', 'integer', 'min:1'],
            'github_report_kind' => ['nullable', Rule::in(['proof', 'success', 'pass', 'fail', 'blocked', 'merged', 'requires_review'])],
            'github_branch' => ['nullable', 'string', 'max:255'],
            'github_pull_request_number' => ['nullable', 'integer', 'min:1'],
            'github_pull_request_url' => ['nullable', 'url:https', 'max:2048'],
            'github_pull_request_head_sha' => ['nullable', 'string', 'regex:/^[a-f0-9]{40}$/i'],
            'github_merge_commit_sha' => ['nullable', 'string', 'regex:/^[a-f0-9]{40}$/i'],
            'report_nonce' => ['nullable', 'string', 'size:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'amp_project_id' => ['required', 'string', 'max:100'],
            'connection_id' => ['required', 'uuid'],
            'controller_thread_id' => ['nullable', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
        ]);

        if ($payload['event_id'] !== $request->attributes->get('amp_event_id')) {
            return response()->json(['message' => 'Amp event ID does not match its signed header.'], 401);
        }

        try {
            if (isset($payload['controller_thread_id'])) {
                $this->connections->acknowledgeController(
                    $ampProjectConnection,
                    $payload['controller_thread_id'],
                    $payload['amp_project_id'],
                    $payload['connection_id'],
                );
            }
            $result = $this->engine->handleAmpCallback(
                $payload,
                hash('sha256', $request->getContent()),
                $ampProjectConnection,
            );
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result, $result['accepted'] ? 200 : 202);
    }

    public function context(Request $request, AmpProjectConnection $ampProjectConnection): JsonResponse
    {
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:context.lookup'],
            'occurred_at' => ['required', 'date'],
            'thread_id' => ['required', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'amp_project_id' => ['required', 'string', 'max:100'],
            'connection_id' => ['required', 'uuid'],
        ]);

        if ($payload['event_id'] !== $request->attributes->get('amp_event_id')) {
            return response()->json(['message' => 'Amp event ID does not match its signed header.'], 401);
        }

        try {
            return response()->json($this->engine->ampContext(
                $payload['thread_id'],
                $ampProjectConnection,
                $payload['amp_project_id'],
                $payload['connection_id'],
            ));
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }

    public function refreshWebhook(Request $request, AmpProjectConnection $ampProjectConnection): JsonResponse
    {
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'uuid'],
            'type' => ['required', 'in:controller.webhook_refreshed'],
            'occurred_at' => ['required', 'date'],
            'connection_id' => ['required', 'uuid'],
            'amp_project_id' => ['required', 'string', 'max:100'],
            'launch_webhook_url' => ['required', 'url:https', 'max:2048'],
            'controller_protocol_version' => ['required', 'integer', 'in:2'],
        ]);

        if ($payload['event_id'] !== $request->attributes->get('amp_event_id')) {
            return response()->json(['message' => 'Amp event ID does not match its signed header.'], 401);
        }

        try {
            $result = $this->connections->refreshWebhook(
                $ampProjectConnection,
                $payload,
                hash('sha256', $request->getContent()),
            );
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result);
    }

    public function stageCapability(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'A stage capability is required.'], 401);
        }

        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['context', 'report_claim', 'report', 'publication', 'merge', 'complete', 'fail'])],
            'occurred_at' => ['required', 'date'],
            'thread_id' => ['required', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'outcome' => ['nullable', 'string', 'max:64'],
            'github_report_url' => ['nullable', 'url:https', 'max:2048'],
            'github_report_comment_id' => ['nullable', 'integer', 'min:1'],
            'github_report_kind' => ['nullable', Rule::in(['proof', 'success', 'pass', 'fail', 'blocked', 'merged', 'requires_review'])],
            'github_branch' => ['nullable', 'string', 'max:255'],
            'github_pull_request_number' => ['nullable', 'integer', 'min:1'],
            'github_pull_request_url' => ['nullable', 'url:https', 'max:2048'],
            'github_pull_request_head_sha' => ['nullable', 'string', 'regex:/^[a-f0-9]{40}$/i'],
            'github_merge_commit_sha' => ['nullable', 'string', 'regex:/^[a-f0-9]{40}$/i'],
            'reason' => ['nullable', 'string', 'max:500'],
            'amp_project_id' => ['required', 'string', 'max:100'],
        ]);

        try {
            if ($payload['action'] === 'context') {
                return response()->json($this->engine->stageCapabilityContext(
                    $token,
                    $payload['thread_id'],
                    $payload['amp_project_id'],
                ));
            }

            $result = $this->engine->handleStageCapability(
                $token,
                $payload,
                hash('sha256', $token."\0".$request->getContent()),
            );
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result, $result['accepted'] ? 200 : 202);
    }

    public function connectionVerification(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'A connection verification capability is required.'], 401);
        }
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['claim', 'started', 'complete', 'failed'])],
            'thread_id' => ['nullable', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'amp_project_id' => ['required', 'string', 'max:100'],
            'github_repository' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'controller_thread_id' => ['nullable', 'required_if:action,claim', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'native_github_access' => ['nullable', 'required_if:action,complete', 'boolean'],
            'failure_code' => ['nullable', 'required_if:action,failed', Rule::in(['controller_thread_failed'])],
        ]);

        try {
            $result = $this->connections->handleVerification($token, $payload);
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result, $result['accepted'] ? 200 : 202);
    }

    public function projectSetup(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'A project setup capability is required.'], 401);
        }
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'action' => ['required', Rule::in(['claim', 'complete'])],
            'setup_id' => ['required', 'uuid'],
            'thread_id' => ['required', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
            'amp_project_id' => ['required', 'string', 'max:100'],
            'launch_webhook_url' => ['nullable', 'required_if:action,complete', 'url:https', 'max:2048'],
            'controller_source_sha256' => ['nullable', 'required_if:action,complete', 'string', 'size:64'],
            'controller_protocol_version' => ['nullable', 'required_if:action,complete', 'integer', 'in:2'],
        ]);

        try {
            $result = $payload['action'] === 'claim'
                ? $this->connections->claimSetup($token, $payload)
                : $this->connections->completeSetup($token, $payload);
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
