<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workflow\Exceptions\WorkflowConflict;
use App\Http\Controllers\Controller;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AmpIntegrationController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function callback(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in([
                'launch.claim',
                'launch.acknowledged',
                'launch.ambiguous',
                'stage.reported',
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
            'report_nonce' => ['nullable', 'string', 'size:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($payload['event_id'] !== $request->attributes->get('amp_event_id')) {
            return response()->json(['message' => 'Amp event ID does not match its signed header.'], 401);
        }

        try {
            $result = $this->engine->handleAmpCallback(
                $payload,
                hash('sha256', $request->getContent()),
            );
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result, $result['accepted'] ? 200 : 202);
    }

    public function context(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'],
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:context.lookup'],
            'occurred_at' => ['required', 'date'],
            'thread_id' => ['required', 'string', 'regex:/^T-[A-Za-z0-9-]+$/'],
        ]);

        if ($payload['event_id'] !== $request->attributes->get('amp_event_id')) {
            return response()->json(['message' => 'Amp event ID does not match its signed header.'], 401);
        }

        try {
            return response()->json($this->engine->ampContext($payload['thread_id']));
        } catch (WorkflowConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }
}
