# Orc Milestones 1–3: Domain and Architecture

This document defines the proposed domain before application code is introduced.

## Boundary

Orc is an orchestration system, not a second source of truth for software work.

- **GitHub owns** requirements, source code, implementation discussion, review discussion, and reports.
- **Laravel stores** GitHub identifiers/URLs and the minimum workflow state required to orchestrate work.
- Orc does not persist private prompts, issue bodies, generated reports, review feedback, or hidden development context.
- During this phase, agent stages can be completed through an explicitly labelled simulation control. The control records only an outcome and a synthetic event; it does not call Amp or publish a GitHub comment. Real Amp execution and GitHub publication are future integrations.

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

An immutable numbered attempt at a stage within a workflow run. An attempt has status/outcome/timestamps and nullable Amp thread/event identifiers reserved for future integration. Historical attempts are never overwritten or deleted. Only one attempt for a workflow may be active at a time; attempt numbers increase monotonically across the whole workflow run, including loops.

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
3. A run has at most one active stage attempt (`pending` or `running`). A database-level partial unique index enforces this on PostgreSQL and SQLite; the engine also enforces it portably under the run lock.
4. A completion identifies the expected stage-attempt ID. A mismatched or already superseded attempt is rejected as stale.
5. Repeating an identical completion request for the same completed attempt is idempotent and returns the unchanged run. A conflicting repeated outcome is rejected.
6. Outcomes must exist as transitions on the attempt's stage. Terminal stages complete the workflow without another attempt.
7. Attempt numbers are allocated under lock as `max(attempt_number) + 1`, so loops retain an ordered history.
8. Cancelled, completed, and failed workflows cannot transition. Cancellation is idempotent and closes the current active attempt.
9. Human actions are accepted only for the current active human attempt and only when permitted by the definition. `request_changes` requires a valid GitHub URL proving feedback was published; Orc records only that URL in the event metadata.
10. Agent simulation is available only for agent attempts and is visibly marked as simulation in both the UI and event stream.

## Service and HTTP shape

- A central `WorkflowEngine` owns start, completion, human action, simulation, and cancellation operations.
- Thin authenticated controllers validate input, authorize the user, and delegate to the engine.
- Route model binding is scoped through the authenticated user's runs; the seeded workflow definition is shared configuration.
- Server-rendered Blade pages provide workflow list/start/detail views and require no JavaScript framework.

## Phase limitations

- No real Amp thread/event creation or callbacks.
- No workflow editor; definitions are seeded in code and the database.
- No GitHub API calls or webhook ingestion.
- No stored review-feedback text. Reviewers publish feedback on GitHub and submit its URL.
- No background workers; simulated completions are synchronous.
