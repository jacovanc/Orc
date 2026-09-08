# Project management and Amp routing design

Status: designed before coding and implemented on 2026-09-08. This phase follows the live-proven Milestone 7 workflow and does not change workflow definition v1–v3.

## Domain

- `Project` is an authenticated user's personal delivery boundary. It has a display name, one canonical normalized GitHub `owner/name`, an Amp project ID discovered during setup, and one current connection version.
- `AmpProjectConnection` is an immutable versioned routing configuration for one Project. It preallocates a public connection ID and connection-scoped controller registration key; its Amp project ID starts empty for a new Project and is atomically bound from the first valid setup claim. It also stores the encrypted durable webhook URL, encrypted independent launch/callback signing secrets, status, and verification evidence.
- `WorkflowRun` snapshots both `project_id` and `amp_project_connection_id`. Every later Development retry, QA attempt, reconciliation, and cancellation uses those original identifiers even if Project settings create a newer connection version.
- `AmpLaunch` also snapshots the connection ID. Queue jobs never select a mutable global endpoint.
- `AmpConnectionSetup` is a short-lived, revocable pairing capability for one preallocated connection. First claim binds it to the exact expected Amp project and setup thread; completion accepts only the pinned controller digest and SSRF-guarded webhook.
- `User.can_trigger_amp` is the explicit immutable account permission. Email is profile data, not authority. Existing operators are migrated once; changing an email never grants execution authority and registrations default to false.

Existing runs are grouped into personal Projects by their existing owner and canonical repository. The existing controller configuration becomes connection version 1. Historical run, attempt, event, report, thread, and launch identifiers are preserved. Only accounts on the deployment's legacy operator allowlist receive the one-time permission migration; merely owning historical or synthetic proof runs does not grant launch authority.

## Routing and verification

Amp's installed Plugin API exposes no `project` selector on `Agent.createThread`. Orc therefore never claims to route by fetching another repository. A launch goes only to the durable webhook registered by a controller running inside the selected Amp project. The setup agent supplies that project's actual `AMP_PROJECT_ID`; Laravel binds it once under a row lock and rejects later project/thread mismatches. The project-scoped controller calls unparented `createThread({ executor: "orb" })`; Laravel refuses to verify or run the connection until the fresh Orb reports the exact bound identity.

The controller compares the connection ID and expected project ID in the signed launch with its owner-only runtime configuration and with the runtime's actual `AMP_PROJECT_ID`. Every worker tool call also carries the child's actual `AMP_PROJECT_ID`, added by worker code rather than model input, and Laravel requires it to match the run's bound connection.

Before a connection becomes ready, Laravel sends a signed, idempotent verification command to its webhook. The matching controller claims the verification, checks its actual project ID, creates a harmless fresh Orb, and prompts the globally installed worker's narrow verification tool. That tool reports the child thread ID, actual project ID, and a read-only native-`gh` access check for the canonical repository. Laravel marks the connection verified only when all identifiers and repository access match. A successful fetch in an unrelated project is not accepted as routing evidence.

## Trust and security boundaries

- Users may view or mutate only Projects and runs they own. Creating/configuring/verifying a Project or launching/retrying an agent also requires `can_trigger_amp`.
- Registration remains opt-in and production-disabled. New accounts never receive Amp authority automatically.
- Controller webhook URLs and both directional secrets are encrypted at rest and exchanged directly with the setup tool over HTTPS, never copied through settings fields, redisplayed, or included in logs or validation messages.
- Outbound controller URLs must be HTTPS capability URLs on explicitly allowed Amp webhook hosts, with no credentials, query, fragment, or nonstandard port. Jobs revalidate before each request and store only sanitized failure reasons.
- Callback routes include the public connection ID and verify with that connection version's callback secret. A callback for one connection cannot mutate a launch bound to another.
- Broad signing secrets stay in the selected trusted controller and Laravel. Coding/QA Orbs receive only their narrow stage capability; verification Orbs receive a one-time connection-verification capability.
- Agents retain the normal Amp toolset plus workflow tools and rely exclusively on user-configured native Orb `git`/`gh` access. Orc never provisions, copies, repairs, or broadens GitHub credentials or repository grants.

## Manual feedback

Humans publish any review comments directly on the GitHub pull request, then choose Request Changes or Approve in Orc. Orc requires neither a feedback URL nor a confirmation checkbox. The fresh Development attempt rereads the issue, exact PR discussion, reviews, inline comments, QA reports, and CI from GitHub before editing.

The previously proposed automated feedback-publication milestone is superseded and will not be built.

## UI and non-goals

The application adds an all-project overview and per-project overview, workflow list/detail/start, settings, connection status, a copyable agent-assisted setup prompt, and verification status/action. It does not add organizations, teams, billing, automatic credential grants, broad account discovery, a workflow editor, or automatic merge/approval.

## Acceptance

Automated tests must prove owner/project isolation, immutable account authority, profile-email non-escalation, connection-scoped signatures, SSRF rejection, encrypted secret storage, two-project launch routing, concurrent runs, configuration-version binding across retries/QA/cancellation, verification project matching, and preservation of existing history. Controller and worker tests must prove actual project-ID propagation and additive default tools.

An actual second-project placement proof requires a user-configured second Amp project, its controller registration, native repository access, and plugin reload. Absence of that external prerequisite does not block shipping the tested application, but Orc must not claim the proof.
