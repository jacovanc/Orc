# Orc Milestones 1–7: Domain and Architecture

This document defines the domain and the integration boundaries before each implementation phase is introduced.

## Boundary

Orc is an orchestration system, not a second source of truth for software work.

- **GitHub owns** requirements, source code, implementation discussion, review discussion, and reports.
- **Laravel stores** GitHub identifiers/URLs and the minimum workflow state required to orchestrate work.
- Orc does not persist private prompts, issue bodies, generated reports, review feedback, or hidden development context.
- Workflow v1 retains the Milestones 4–5 proof behavior. Workflow v2 uses a real Development agent followed by QA integration proof and an explicit human release gate. Workflow v3 adds independent substantive QA with remediation and blocked-operator loops. Simulation remains a local-development fallback only when integration is disabled.

## Domain model

### WorkflowDefinition

An immutable, versioned workflow graph. `key` identifies the workflow family and `version` identifies a frozen revision. Published definitions are never edited in place; changes create another version.

### WorkflowStage

A node in a definition with a stable `key`, display `name`, `type`, configuration, and display `position`.

Stage types:

- `agent`: future Amp-driven work. Configuration may name an agent role, but not contain task requirements.
- `human`: waits for an authorized user to choose a permitted outcome. Reviewers may publish feedback directly on GitHub, but Orc neither stores that text nor requires a link or confirmation before accepting the decision.
- `terminal`: an end state such as Done.

### WorkflowTransition

A directed edge identified by `(workflow_definition_id, from_stage_id, outcome)`. It points to exactly one destination stage. This uniqueness makes an outcome deterministic.

### WorkflowRun

One execution of a frozen definition for a GitHub repository and issue. It snapshots its owner-scoped Project and immutable Amp controller connection version, then stores repository owner/name, issue number/URL, status, current stage, lifecycle timestamps, and no issue content. Later Project settings changes cannot move a run.

### Project and AmpProjectConnection

`Project` is one user's personal boundary for a canonical GitHub repository and explicit Amp project identity. `AmpProjectConnection` is an immutable numbered controller configuration with an encrypted durable webhook URL, encrypted directional secrets, status, and fresh-Orb verification evidence. `createThread` has no project selector, so correct placement comes from sending the launch to a controller registered inside the selected Amp project—not from fetching a repository in some other project.

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

Workflow v2 and v3 are separately frozen definitions documented in [Milestone 6](milestone-6-design.md) and [Milestone 7](milestone-7-design.md). Version 3 routes Development success to substantive QA; QA pass to Human Review, fail to a fresh Development attempt, and blocked to an explicit human QA-blocked gate.

## State machine invariants

1. `WorkflowEngine` is the only application service allowed to start, complete, transition, or cancel a run.
2. Mutations occur in database transactions while locking the workflow run and current stage attempt.
3. A run has at most one active stage attempt (`running` or `waiting`). A nullable `active_slot` plus a unique `(workflow_run_id, active_slot)` constraint enforces this portably: the active attempt owns slot `1`, while any number of closed attempts have `NULL`.
4. A completion identifies the expected stage-attempt ID. A mismatched or already superseded attempt is rejected as stale.
5. Repeating an identical completion request for the same completed attempt is idempotent and returns the unchanged run. A conflicting repeated outcome is rejected.
6. Outcomes must exist as transitions on the attempt's stage. Entering a terminal stage creates an immediately closed attempt, then completes the workflow.
7. Attempt numbers are allocated under the run lock from the current (and therefore highest) attempt, so loops retain a monotonically ordered history.
8. Cancelled, completed, and failed workflows cannot transition. Cancellation is idempotent and closes the current active attempt.
9. Human actions are accepted only for the current active human attempt and only when permitted by the definition. Approval completes directly; `request_changes` starts one fresh Development attempt whose agent rereads the bound pull request, reviews, inline comments, and discussion from GitHub.
10. Agent simulation is available only when Amp integration is disabled and is visibly marked as simulation in both the UI and event stream.

## Service and HTTP shape

- A central `WorkflowEngine` owns start, completion, human action, simulation, and cancellation operations.
- Thin authenticated controllers validate input, authorize the user, and delegate to the engine.
- Controllers return 404 unless a route-bound run belongs to the authenticated user; the engine repeats this ownership invariant at the mutation boundary. The seeded workflow definition is shared configuration.
- Server-rendered Blade pages provide workflow list/start/detail views and require no JavaScript framework.

## Milestones 4–7 integration boundary

### Directional authentication

Laravel and the trusted Amp controller use independent HMAC-SHA256 secrets. Laravel signs launch, reconciliation, and cancellation requests with the launch secret; the controller signs launch claims and acknowledgements with the callback secret. A signature covers the exact request body, event ID, and Unix timestamp. Receivers reject missing signatures, mismatched event IDs, and timestamps outside the configured skew window. The durable Amp webhook URL is also a bearer capability and is stored only in deployment configuration. Fresh workers receive neither directional secret.

The project-local plugin is the trusted controller. It owns webhook registration, launch claims, thread creation, and monitoring; its independent launch/callback signing secrets exist only in an owner-only local runtime file in the webhook-hosting Orb. A global User Plugin adds workflow tools in fresh Orbs without requiring project secrets. Every agent retains the normal Amp tools for its extended built-in mode.

Laravel creates a random per-launch capability, stores its recoverable form encrypted and its lookup hash separately, and sends it through the signed launch. The controller supplies that capability to the bound thread. The capability can only query or mutate one current stage attempt with the exact bound thread and permitted outcomes; it cannot launch threads, target another run, grant repository access, or bypass Laravel's locks. Thus a full-shell coding Orb never receives a reusable callback signing secret.

GitHub and Git authentication are not Orc capabilities. Agents and worker tools use the user's existing native Orb `git`/`gh` authentication. Missing access blocks work; Orc never provisions, copies, injects, repairs, or broadens credentials.

### At-most-once launch protocol

Each agent `StageRun` gets one durable `AmpLaunch` record with stable event and idempotency keys. Laravel stores the canonical request body encrypted and resends those exact bytes on bounded retries. Delivery status and business launch status are separate so a callback may safely arrive before the launch HTTP response.

Before creating a thread, the plugin obtains a transactional launch claim from Laravel. The first valid claim changes the persistent launch status from `pending` to `claimed`; duplicate webhook deliveries are denied. Only the holder of that claim calls `createThread({ executor: 'orb' })`, producing a fresh private thread and a fresh Orb for that stage attempt. Laravel then binds the returned thread ID to the still-current attempt. A retry never calls `createThread` after a claim, even if an acknowledgement was lost.

This deliberately chooses at-most-once creation over blind recovery. A duplicate delivery after a claim can recover a durably bound thread, but a claim with no thread is marked ambiguous and never creates another Orb. After acknowledgement, bounded signed reconciliation deliveries recover an idempotent prompt (identified by a stable transcript marker) and controller monitor after process restart. A definitive non-retryable launch rejection is `failed`; unresolved uncertainty is `ambiguous` and requires inspection or cancellation.

### Callback processing

Callback event IDs and payload hashes are persisted. Replaying the same event and payload returns the stored disposition; reusing an event ID with another payload is rejected. The central `WorkflowEngine` locks the callback event, launch, workflow, and expected attempt before binding a thread, transitioning an outcome, or recording a failure. A callback must identify the current attempt and its exact bound Amp thread. Cancelled workflows, superseded attempts, conflicting outcomes, and foreign threads are recorded as rejected integration events without mutating the workflow.

An explicit `workflow_complete` tool is the normal completion path. A guarded `agent.end` hook/controller monitor gives the agent one corrective turn if it forgets the tool, then reports failure rather than guessing an outcome. Each launch has an unguessable report nonce and an exclusive persistent publication claim. The worker accepts an existing marker only from the native authenticated GitHub identity, paginates reconciliation, and uses the narrow capability to attest the report. Laravel durably binds that comment ID/URL and report kind before completion; report text remains only on GitHub.

Real QA uses a distinct `real_qa` mode and fresh Orb. Its context tool reads the exact prior Development PR, reviews, issue and inline comments, changed files, commits, check runs, and commit status directly from GitHub. Its report must be a comment on that exact PR, and its attested kind must equal `pass`, `fail`, or `blocked`. QA has normal tools but is instructed and scoped not to publish code; Laravel exposes no publication operation for QA. A fail starts a new Development attempt that rereads GitHub and updates the existing PR branch. A blocked result enters a human gate rather than being represented as pass or fail.

Cancellation closes the Laravel attempt first, transactionally preventing any late completion. When a thread is already bound, Laravel also queues a signed, retryable command to the trusted controller to call `thread.cancel()` on that exact thread. External GitHub or Git operations already in flight may still finish and must be inspected.

Self-registration is opt-in and source-default-disabled. Independently, workflow start and every human transition into an agent stage require the authenticated owner's immutable `users.can_trigger_amp` permission and the run's verified connection snapshot. New registrations default false, and profile/email changes never alter this permission. Production registration is disabled. Owner/project authorization, exact connection/thread/stage binding, and connection-scoped signatures prevent another account or controller from directing the owner's Amp identity.

## Current limitations

- No workflow editor; definitions are seeded in code and the database.
- No stored review-feedback text. Reviewers publish any feedback directly on GitHub, then choose Approve or Request Changes in Orc without a separate URL or confirmation field.
- Real Development was live-proven on user-authorized `jacovanc/Orc#2`; public-repository operation is proven, private-repository operation is not claimed.
- QA in workflow v2 remains orchestration proof only, named `proof_complete`, and is never code validation or approval. New runs may select workflow v3 for independent substantive QA.
- Ambiguous launches are surfaced for operator action rather than automatically retried into a possible duplicate.
