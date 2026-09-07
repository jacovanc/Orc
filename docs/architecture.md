# Orc Milestones 1–5: Domain and Architecture

This document defines the domain and the integration boundaries before each implementation phase is introduced.

## Boundary

Orc is an orchestration system, not a second source of truth for software work.

- **GitHub owns** requirements, source code, implementation discussion, review discussion, and reports.
- **Laravel stores** GitHub identifiers/URLs and the minimum workflow state required to orchestrate work.
- Orc does not persist private prompts, issue bodies, generated reports, review feedback, or hidden development context.
- Agent stages use narrowly scoped Amp proof agents in Milestones 4–5. They may read the selected issue, publish an explicitly labelled test report, and complete their attempt; they cannot edit code. The simulation control remains a local-development fallback when integration is disabled.

## Domain model

### WorkflowDefinition

An immutable, versioned workflow graph. `key` identifies the workflow family and `version` identifies a frozen revision. Published definitions are never edited in place; changes create another version.

### WorkflowStage

A node in a definition with a stable `key`, display `name`, `type`, configuration, and display `position`.

Stage types:

- `agent`: future Amp-driven work. Configuration may name an agent role, but not contain task requirements.
- `human`: waits for an authorized user to choose a permitted outcome. Outcomes that request changes require feedback, but the feedback must be published to GitHub rather than stored in Orc. Until GitHub publication exists, the MVP accepts a GitHub discussion/comment URL as proof of publication.
- `terminal`: an end state such as Done.

### WorkflowTransition

A directed edge identified by `(workflow_definition_id, from_stage_id, outcome)`. It points to exactly one destination stage. This uniqueness makes an outcome deterministic.

### WorkflowRun

One execution of a frozen definition for a GitHub repository and issue. It stores repository owner/name, issue number/URL, status, current stage, lifecycle timestamps, and no issue content.

### StageRun

An immutable numbered attempt at a stage within a workflow run. An attempt has status/outcome/timestamps and nullable Amp thread/event identifiers populated by the Milestones 4–5 adapter. Historical attempts are never overwritten or deleted. Only one attempt for a workflow may be active at a time; attempt numbers increase monotonically across the whole workflow run, including loops.

### WorkflowEvent

An append-only audit record of orchestration facts. Events contain structured identifiers and state-change metadata only, not requirement text, agent prompts, reports, or review feedback. There are no update or delete paths in the application.

## Seeded development workflow (version 1)

```text
Development (agent) --success--------> QA (agent)
QA          (agent) --fail-----------> Development (agent)
QA          (agent) --pass-----------> Human Review (human)
Human Review (human) --request_changes> Development (agent)
Human Review (human) --approve--------> Done (terminal)
```

## State machine invariants

1. `WorkflowEngine` is the only application service allowed to start, complete, transition, or cancel a run.
2. Mutations occur in database transactions while locking the workflow run and current stage attempt.
3. A run has at most one active stage attempt (`running` or `waiting`). A nullable `active_slot` plus a unique `(workflow_run_id, active_slot)` constraint enforces this portably: the active attempt owns slot `1`, while any number of closed attempts have `NULL`.
4. A completion identifies the expected stage-attempt ID. A mismatched or already superseded attempt is rejected as stale.
5. Repeating an identical completion request for the same completed attempt is idempotent and returns the unchanged run. A conflicting repeated outcome is rejected.
6. Outcomes must exist as transitions on the attempt's stage. Entering a terminal stage creates an immediately closed attempt, then completes the workflow.
7. Attempt numbers are allocated under the run lock from the current (and therefore highest) attempt, so loops retain a monotonically ordered history.
8. Cancelled, completed, and failed workflows cannot transition. Cancellation is idempotent and closes the current active attempt.
9. Human actions are accepted only for the current active human attempt and only when permitted by the definition. `request_changes` requires a valid GitHub URL proving feedback was published; Orc records only that URL in the event metadata.
10. Agent simulation is available only when Amp integration is disabled and is visibly marked as simulation in both the UI and event stream.

## Service and HTTP shape

- A central `WorkflowEngine` owns start, completion, human action, simulation, and cancellation operations.
- Thin authenticated controllers validate input, authorize the user, and delegate to the engine.
- Controllers return 404 unless a route-bound run belongs to the authenticated user; the engine repeats this ownership invariant at the mutation boundary. The seeded workflow definition is shared configuration.
- Server-rendered Blade pages provide workflow list/start/detail views and require no JavaScript framework.

## Milestones 4–5 integration boundary

### Directional authentication

Laravel and the Amp integration use independent HMAC-SHA256 secrets. Laravel signs launch requests with the launch secret; Amp signs claims, acknowledgements, context lookups, completions, and safety-net failures with the callback secret. A signature covers the exact request body, a unique event ID, and a Unix timestamp. Receivers reject missing signatures, mismatched event IDs, and timestamps outside the configured skew window. The durable Amp webhook URL is also a bearer capability and is stored only in deployment configuration.

The project-local plugin owns webhook registration, launch claims, thread creation, and monitoring. A global User Plugin publishes the three worker tools in fresh Orbs; it returns without registering anything unless Orc's project-scoped configuration is present. The proof agent never receives either signing secret, the webhook URL, or a GitHub token. Its custom agent exposes only three closure-backed tools: read the bound GitHub issue, publish one labelled test report to that issue, and complete the bound workflow attempt. It has no shell, file, generic network, or code-editing tools.

### At-most-once launch protocol

Each agent `StageRun` gets one durable `AmpLaunch` record with stable event and idempotency keys. A queued Laravel job sends the same signed body and `Idempotency-Key` on every bounded retry. Delivery status and business launch status are separate so a callback may safely arrive before the launch HTTP response.

Before creating a thread, the plugin obtains a transactional launch claim from Laravel. The first valid claim changes the persistent launch status from `pending` to `claimed`; duplicate webhook deliveries are denied. Only the holder of that claim calls `createThread({ executor: 'orb' })`, producing a fresh private thread and a fresh Orb for that stage attempt. Laravel then binds the returned thread ID to the still-current attempt. A retry never calls `createThread` after a claim, even if an acknowledgement was lost.

This deliberately chooses at-most-once creation over blind recovery. A definitive non-retryable launch rejection is recorded as `failed`. Exhausted transport retries or failure after a claim are recorded as `ambiguous` and require inspection or cancellation; Orc never risks creating a duplicate Orb to hide uncertainty.

### Callback processing

Callback event IDs and payload hashes are persisted. Replaying the same event and payload returns the stored disposition; reusing an event ID with another payload is rejected. The central `WorkflowEngine` locks the callback event, launch, workflow, and expected attempt before binding a thread, transitioning an outcome, or recording a failure. A callback must identify the current attempt and its exact bound Amp thread. Cancelled workflows, superseded attempts, conflicting outcomes, and foreign threads are recorded as rejected integration events without mutating the workflow.

An explicit `workflow_complete` tool is the normal completion path. A guarded `agent.end` hook gives the agent one corrective turn if it forgets the tool, then reports an agent failure rather than guessing an outcome. GitHub comment URLs are stored as report references; report text remains only on GitHub.

## Current limitations

- No workflow editor; definitions are seeded in code and the database.
- No stored review-feedback text. Reviewers publish feedback on GitHub and submit its URL.
- Development and QA agents are harmless integration proofs only: they do not modify code, create branches, or open pull requests. Those capabilities begin in Milestone 6 or later.
- Ambiguous launches are surfaced for operator action rather than automatically retried into a possible duplicate.
