# Orc Milestones 1–7 and Merge: Domain and Architecture

This document defines the domain and the integration boundaries before each implementation phase is introduced.

## Boundary

Orc is an orchestration system, not a second source of truth for software work.

- **GitHub owns** requirements, source code, implementation discussion, review discussion, and reports.
- **Laravel stores** GitHub identifiers/URLs, owner-configured substantive stage instructions, immutable per-run instruction snapshots, and the minimum workflow state required to orchestrate work.
- Orc does not copy GitHub task content into those instructions or persist issue bodies, generated reports, review questions/feedback, or hidden development context.
- Workflow v1 retains the Milestones 4–5 proof behavior. Workflow v2 uses a real Development agent followed by QA integration proof and an explicit human release gate. Workflow v3 adds independent substantive QA with remediation and blocked-operator loops. Workflow v4 adds a fresh policy-bound Merge agent after Human Review. Workflow v5 adds an optional fresh read-only Explanation loop and versioned project stage instructions. Simulation remains a local-development fallback only when integration is disabled.

## Domain model

### WorkflowDefinition

An immutable, versioned workflow graph. `key` identifies the workflow family and `version` identifies a frozen revision. Published definitions are never edited in place; changes create another version.

### WorkflowStage

A node in a definition with a stable `key`, display `name`, `type`, configuration, and display `position`.

Stage types:

- `agent`: Amp-driven work. Configuration names a fixed agent mode; the substantive task body is resolved from the Project and snapshotted once when the run starts rather than embedded in the immutable graph.
- `human`: waits for an authorized user to choose a permitted outcome. Reviewers may publish feedback directly on GitHub, but Orc neither stores that text nor requires a link or confirmation before accepting the decision.
- `terminal`: an end state such as Done.

### WorkflowTransition

A directed edge identified by `(workflow_definition_id, from_stage_id, outcome)`. It points to exactly one destination stage. This uniqueness makes an outcome deterministic.

### WorkflowRun

One execution of a frozen definition for a GitHub repository and issue. It snapshots its owner-scoped Project and immutable Amp controller connection version, then stores repository owner/name, issue number/URL, status, current stage, lifecycle timestamps, and no issue content. Later Project settings changes cannot move a run.

### Project and AmpProjectConnection

`Project` is one user's personal boundary for a canonical GitHub repository. Its Amp project identity is not user-entered: the first valid setup claim binds the actual `AMP_PROJECT_ID` under a row lock. `AmpProjectConnection` is an immutable numbered controller configuration with a connection-scoped webhook key, encrypted durable webhook URL, encrypted directional secrets, status, and fresh-Orb verification evidence. `createThread` has no project selector, so correct placement comes from sending the launch to a controller registered inside the selected Amp project—not from fetching a repository in some other project.

### StageRun

An immutable numbered attempt at a stage within a workflow run. An attempt has status/outcome/timestamps and nullable Amp thread/event identifiers populated by the Milestones 4–5 adapter. Historical attempts are never overwritten or deleted. Only one attempt for a workflow may be active at a time; attempt numbers increase monotonically across the whole workflow run, including loops.

### WorkflowEvent

An append-only audit record of orchestration facts. Events contain structured identifiers and state-change metadata only, not requirement text, agent prompts, reports, or review feedback. There are no update or delete paths in the application.

### WorkflowAttentionDelivery

A deduplicated operational outbox record for notifying the workflow owner that a human StageRun needs attention. The unique stage-attempt/channel binding is created inside the same transaction that enters the stage; provider delivery occurs after commit on a bounded-retry queue. The job skips attempts that are no longer active and waiting. This record contains delivery state and sanitized failure text, never Mailgun credentials or substantive workflow context.

### Manual run control

An owner may pause the current agent attempt or manually move a running, paused, or failed run to another non-terminal stage in the same immutable definition. This is an audited run-level override, not an edit to the definition or history: the current attempt is closed, any bound Amp thread receives the existing signed cancellation command, and a new monotonically numbered attempt is created at the selected stage. Completed and cancelled runs remain final; workflow v4 reaches Done only through verified Merge completion after the human approval transition.

Manual movement is addressed to the expected attempt ID so duplicate submissions are idempotent and racing agent callbacks become stale. Moving into an agent stage rechecks the owner's immutable Amp permission and the run's original verified connection. Moving directly to Human Review is allowed for recovery or deliberate stage skipping, but the UI labels it as a manual override rather than claiming QA passed. Orc stores only the actor and structured from/to/attempt identifiers in append-only events; it does not invent or store review feedback.

## Seeded development workflow (version 1)

```text
Development (agent) --success--------> QA (agent)
QA          (agent) --fail-----------> Development (agent)
QA          (agent) --pass-----------> Human Review (human)
Human Review (human) --request_changes> Development (agent)
Human Review (human) --approve--------> Done (terminal)
```

Workflow v2 and v3 are separately frozen definitions documented in [Milestone 6](milestone-6-design.md) and [Milestone 7](milestone-7-design.md). Version 3 routes Development success to substantive QA; QA pass to Human Review, fail to a fresh Development attempt, and blocked to an explicit human QA-blocked gate. [Workflow v4](milestone-merge-design.md) routes approval to Merge; verified merge reaches Done, one material conflict resolution returns through QA/Human Review, and blocked or later material conflicts stop at Merge Blocked Review. Version 5 preserves that graph and adds `Human Review --ask_questions--> Explanation`, with both honest Explanation outcomes returning to Human Review.

### Stage task instructions

Each Project has an append-only version stream for the substantive Development, QA, Explanation, and Merge task bodies. The owner can read and edit these plain-text bodies in Project settings. Fixed authorization, evidence, native-auth, exact issue/PR, and role-permission constraints are kept in a trusted orchestration envelope and cannot be edited there.

At workflow start Laravel snapshots every applicable current body and source version into the run. Launches, retries, remediation loops, and manual moves always use that snapshot, so a later Project edit affects only new workflows. Historical runs without snapshots remain truthful: the UI does not claim to know an exact historical body it never stored. Task bodies never contain capability values or connection signing material and are rendered escaped.

## State machine invariants

1. `WorkflowEngine` is the only application service allowed to start, complete, transition, or cancel a run.
2. Mutations occur in database transactions while locking the workflow run and current stage attempt.
3. A run has at most one active stage attempt (`running` or `waiting`). A nullable `active_slot` plus a unique `(workflow_run_id, active_slot)` constraint enforces this portably: the active attempt owns slot `1`, while any number of closed attempts have `NULL`.
4. A completion identifies the expected stage-attempt ID. A mismatched or already superseded attempt is rejected as stale.
5. Repeating an identical completion request for the same completed attempt is idempotent and returns the unchanged run. A conflicting repeated outcome is rejected.
6. Outcomes must exist as transitions on the attempt's stage. Entering a terminal stage creates an immediately closed attempt, then completes the workflow.
7. Attempt numbers are allocated under the run lock from the current (and therefore highest) attempt, so loops retain a monotonically ordered history.
8. Completed and cancelled workflows cannot transition. Cancellation is idempotent and closes the current active attempt. An audited manual override may recover a failed run without rewriting its failed attempt.
9. Human actions are accepted only for the current active human attempt and only when permitted by the definition. In v1–v3 approval completes directly; in v4+ it starts one fresh Merge attempt. `request_changes` starts one fresh Development attempt whose agent rereads the bound pull request, reviews, inline comments, and discussion from GitHub. In v5, `ask_questions` starts one fresh read-only Explanation attempt after the human posts questions on GitHub; `completed` and clarification-needed `blocked` both return to Human Review and never imply QA or approval.
10. Agent simulation is available only when Amp integration is disabled and is visibly marked as simulation in both the UI and event stream.
11. Manual pause and stage movement identify the expected attempt, preserve monotonic attempt numbering, reject cross-definition or terminal destinations, and cancel a bound agent thread before queuing any replacement agent attempt.
12. When email is enabled, every newly created human attempt schedules at most one owner notification under a database uniqueness constraint. Disabled notifications create no backlog, and delayed jobs do not email for stale attempts.

## Service and HTTP shape

- A central `WorkflowEngine` owns start, completion, human action, simulation, pause, audited stage override, and cancellation operations.
- Thin authenticated controllers validate input, authorize the user, and delegate to the engine.
- Controllers return 404 unless a route-bound run belongs to the authenticated user; the engine repeats this ownership invariant at the mutation boundary. The seeded workflow definition is shared configuration.
- Server-rendered Blade pages provide workflow list/start/detail views and require no JavaScript framework.

## Milestones 4–7 integration boundary

### Directional authentication

Laravel and the trusted Amp controller use independent HMAC-SHA256 secrets per immutable Project connection. Laravel signs launch, reconciliation, and cancellation requests with the launch secret; the controller signs launch claims and acknowledgements with the callback secret. A signature covers the exact request body, event ID, and Unix timestamp. Receivers reject missing signatures, mismatched event IDs, and timestamps outside the configured skew window. The durable Amp webhook URL is also a bearer capability; all three values are encrypted in Laravel and stored only in the trusted controller's owner-readable runtime. Fresh workflow workers receive neither directional secret.

The project-local plugin is the trusted controller. It owns webhook registration, launch claims, thread creation, and monitoring; its independent launch/callback signing secrets exist only in an owner-only local runtime file in the webhook-hosting Orb. A global User Plugin adds only project setup, connection verification, and stage completion capabilities in fresh Orbs without requiring project secrets. Every agent retains the normal Amp tools for its extended built-in mode.

Laravel creates a random per-launch capability, stores its recoverable form encrypted and its lookup hash separately, and sends it through the signed launch. The controller supplies that capability to the bound thread. The capability can only query or mutate one current stage attempt with the exact bound thread and permitted outcomes; it cannot launch threads, target another run, grant repository access, or bypass Laravel's locks. Thus a full-shell coding Orb never receives a reusable callback signing secret.

GitHub and Git authentication are not Orc capabilities. Agents and worker tools use the user's existing native Orb `git`/`gh` authentication. Missing access blocks work; Orc never provisions, copies, injects, repairs, or broadens credentials.

### At-most-once launch protocol

Each agent `StageRun` gets one durable `AmpLaunch` record with stable event and idempotency keys. Laravel stores the canonical request body encrypted and resends those exact bytes on bounded retries. Delivery status and business launch status are separate so a callback may safely arrive before the launch HTTP response.

Before creating a thread, the plugin obtains a transactional launch claim from Laravel. The first valid claim changes the persistent launch status from `pending` to `claimed`; duplicate webhook deliveries are denied. Only the holder of that claim calls `createThread({ executor: 'orb' })`, producing a fresh private thread and a fresh Orb for that stage attempt. Laravel then binds the returned thread ID to the still-current attempt. A retry never calls `createThread` after a claim, even if an acknowledgement was lost.

This deliberately chooses at-most-once creation over blind recovery. A duplicate delivery after a claim can recover a durably bound thread, but a claim with no thread is marked ambiguous and never creates another Orb. After acknowledgement, bounded signed reconciliation deliveries recover an idempotent prompt (identified by a stable transcript marker) and controller monitor after process restart. A definitive non-retryable launch rejection is `failed`; unresolved uncertainty is `ambiguous` and requires inspection or cancellation.

### Callback processing

Callback event IDs and payload hashes are persisted. Replaying the same event and payload returns the stored disposition; reusing an event ID with another payload is rejected. The central `WorkflowEngine` locks the callback event, launch, workflow, and expected attempt before binding a thread, transitioning an outcome, or recording a failure. A callback must identify the current attempt and its exact bound Amp thread. Cancelled workflows, superseded attempts, conflicting outcomes, and foreign threads are recorded as rejected integration events without mutating the workflow.

An explicit `workflow_complete` tool is the normal completion path. A guarded `agent.end` hook/controller monitor gives the agent one corrective turn if it forgets the tool, then reports failure rather than guessing an outcome. Each launch has an unguessable report nonce. The agent creates its report with normal native `gh`; the completion capability independently verifies the exact marker, target, and native authenticated GitHub identity, then uses the narrow capability to attest the report. Laravel durably binds that comment ID/URL and report kind before completion; report text remains only on GitHub.

Real QA uses a distinct `real_qa` mode and fresh Orb. It reads the exact prior Development PR, reviews, issue and inline comments, changed files, commits, check runs, and commit status directly from GitHub with normal native tools. Its report must be a comment on that exact PR, and its attested kind must equal `pass`, `fail`, or `blocked`. QA has normal tools but is instructed and scoped not to publish code; Laravel exposes no publication operation for QA. A fail starts a new Development attempt that rereads GitHub and updates the existing PR branch. A blocked result enters a human gate rather than being represented as pass or fail.

Real Merge uses a distinct `real_merge` mode and fresh Orb after v4+ Human Review approval. It starts only with a verified Development PR and exact head from the latest passing QA attempt. It rereads live GitHub state and repository policy with normal native tools. Clean or clearly mechanical behavior-preserving conflicts may merge; a first material conflict resolution is pushed but routed through fresh QA/Human Review, and any later or uncertain conflict is blocked. Worker v2+ verifies the exact marker-bound PR report and live PR/head/merge-commit evidence before Laravel records `stage.merge_verified` and permits Done.

Real Explanation uses `real_explanation` in a fresh Orb after the v5 Human Review owner chooses Ask questions. GitHub remains the question and answer store: the agent rereads the issue, exact PR/code, reviews, inline comments, recent discussion, prior reports, and checks. It replies to each inline question inside that existing review thread so file/line context and follow-up discussion remain attached, then posts only a brief marker-bound completion report linking those answers. Top-level questions without a reply target receive separate focused linked responses. It may run read-only inspections but cannot edit, commit, push, merge, approve, request changes, or start Development. If there is no clear unanswered question, it reports clarification needed honestly and returns to Human Review rather than inventing one.

Cancellation closes the Laravel attempt first, transactionally preventing any late completion. When a thread is already bound, Laravel also queues a signed, retryable command to the trusted controller to call `thread.cancel()` on that exact thread. External GitHub or Git operations already in flight may still finish and must be inspected.

Self-registration is opt-in and source-default-disabled. Independently, workflow start and every human transition into an agent stage require the authenticated owner's immutable `users.can_trigger_amp` permission and the run's verified connection snapshot. New registrations default false, and profile/email changes never alter this permission. Production registration is disabled. Owner/project authorization, exact connection/thread/stage binding, and connection-scoped signatures prevent another account or controller from directing the owner's Amp identity.

## Current limitations

- No workflow-graph editor; definitions remain seeded and immutable. Project owners can version only the substantive task body for each real agent role.
- No stored review-feedback text. Reviewers publish any feedback directly on GitHub, then choose Approve or Request Changes in Orc without a separate URL or confirmation field.
- Real Development was live-proven on user-authorized `jacovanc/Orc#2`; public-repository operation is proven, private-repository operation is not claimed.
- QA in workflow v2 remains orchestration proof only, named `proof_complete`, and is never code validation or approval. New runs may select workflow v3 for independent substantive QA.
- Workflow v4 requires a verified controller-protocol-2 connection. Workflow v5 requires protocol 3/v13 for Explanation and versioned task-body delivery. Historical connections and v1–v4 runs remain valid but are not silently upgraded.
- Live Merge execution has not been proven because this release did not have authorization to merge a real pull request.
- Ambiguous launches are surfaced for operator action rather than automatically retried into a possible duplicate.
