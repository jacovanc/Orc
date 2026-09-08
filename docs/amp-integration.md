# Laravel↔Amp integration runbook

This runbook covers the Milestones 4–6 controller/worker boundary, deployment configuration, failure handling, and proof status.

## Components and trust boundary

- `AmpLaunch` is one durable command per agent `StageRun`. Its canonical body is encrypted at rest and its event/idempotency keys remain stable across delivery retries.
- `DeliverAmpLaunch` uses the `amp-launches` database queue, 3-second connect and 10-second request timeouts, four attempts, and bounded backoff.
- `ReconcileAmpLaunch` sends bounded, signed recovery deliveries after acknowledgement. The controller finds the already-bound thread, reconciles the stable prompt marker, and restores monitoring without creating another Orb.
- `DeliverAmpCancellation` sends a signed, retryable cancellation command for the exact bound thread after Laravel has transactionally cancelled the attempt.
- `AmpIntegrationEvent` persistently deduplicates mutating callback event IDs and payload hashes. `WorkflowEvent` remains append-only.
- `WorkflowEngine` is the sole workflow mutation boundary. It locks launch, run, and attempt rows before claims, bindings, evidence, failures, or transitions.
- `.amp/plugins/orc-integration` is the trusted, project-local controller. Only an Orb with owner-only runtime configuration registers its webhook, agent modes, and lifecycle handlers.
- The global `orc-worker` User Plugin registers workflow tools in fresh Orbs without reading project configuration or broad secrets.

Workers retain normal Amp shell, editing, web, MCP, and other tools. The controller uses `extends: 'medium'` with `tools.add`, which the installed Plugin API documents as additive to the built-in mode's defaults. Development is pinned to `openai/gpt-5.6-sol`. Authorization is enforced by Laravel bindings, not by pretending that a coding sandbox has no general tools.

## GitHub authentication prerequisite

Orc relies exclusively on GitHub repository access already configured by the user for a fresh Amp Orb:

- normal agent Git operations use the Orb's native Git authentication;
- worker API operations invoke native `gh`;
- Orc never creates, copies, injects, repairs, rotates, or broadens GitHub credentials or repository grants;
- no Orc `GH_TOKEN`, `GITHUB_TOKEN`, `ORC_GITHUB_TOKEN`, credential helper, or GitHub project secret exists;
- missing issue read, comment, push, or pull-request access is a clear prerequisite failure/`blocked` outcome.

Do not infer private access from a public repository check. Verify a private target only when the user explicitly designates an authorized private repository and issue.

## Controller secret setup

Generate two independent random values of at least 32 bytes. Never reuse a secret between directions. Store these only in gitignored `.amp/runtime/orc-plugin.json` in the webhook-hosting controller Orb with mode `0600`:

```json
{
  "callbackUrl": "https://your-orc.example/api/integrations/amp",
  "launchSigningSecret": "generated Laravel-to-Amp secret",
  "callbackSigningSecret": "different generated Amp-to-Laravel secret"
}
```

There is deliberately no GitHub credential in this file and no controller secret in the global worker or Amp project secrets. Without this file, the project plugin returns before registering a webhook, lifecycle hook, or controller agent mode; this separates untrusted coding Orbs from the trusted controller role.

Reload the project plugin in the configured Amp-managed controller Orb. Registration key `orc-stage-launch-v2` deliberately replaced the proof-only v1 handler during Milestone 6 acceptance; future incompatible controller contracts must use another versioned key. The controller writes the capability URL to `.amp/runtime/launch-webhook-url` with mode `0600`; treat that URL like a secret and update Laravel's `AMP_LAUNCH_WEBHOOK_URL` whenever the registration key changes.

Configure Laravel without exposing values:

```dotenv
QUEUE_CONNECTION=database
AMP_INTEGRATION_ENABLED=true
AMP_LAUNCH_WEBHOOK_URL=<durable controller webhook URL>
AMP_LAUNCH_SIGNING_SECRET=<same launch secret>
AMP_CALLBACK_SIGNING_SECRET=<same callback secret>
AMP_SIGNATURE_TOLERANCE_SECONDS=300
AMP_ALLOWED_REPOSITORIES=approved-owner/approved-repository
AMP_ALLOWED_USER_EMAILS=approved-operator@example.com
REGISTRATION_ENABLED=false
```

Both allowlists are mandatory and fail closed while integration is enabled. Repository matching is case-insensitive. The account allowlist prevents an authenticated Orc user from initiating work under the controller owner's native Amp identity. Self-registration is source-default-disabled; keep `REGISTRATION_ENABLED=false` in production and provision operators separately. Enabling registration does not add an email to `AMP_ALLOWED_USER_EMAILS` and therefore cannot grant Amp launch authority by itself.

Run a supervised queue worker:

```bash
php artisan queue:work database --queue=amp-launches --tries=4 --backoff=5 --timeout=30
```

Enable integration last. For directional-secret rotation, disable new launches, finish or cancel active attempts, replace both ends of one direction together, reload/restart, verify, and re-enable.

## Authentication and stage capability

Laravel launch/reconciliation/cancellation requests and trusted-controller callbacks sign `timestamp + "." + event_id + "." + exact_body` with HMAC-SHA256. Headers are `X-Orc-Signature`, `X-Orc-Event-Id`, and `X-Orc-Timestamp`; receivers use constant-time comparison and reject stale timestamps. Launch and callback secrets are separate.

No broad signing secret enters a coding Orb. Laravel generates a random capability per `AmpLaunch`, stores the recoverable token encrypted and its SHA-256 lookup separately, and sends it in the signed launch. The controller supplies it only to the exact spawned thread. The public capability endpoint can act only on that launch's current attempt, exact bound thread, configured mode/outcomes, expected branch, same-repository pull request, and bound issue report. It cannot launch a thread, access another run, grant repository access, or override cancellation/staleness checks.

The capability is model-visible because the agent must supply it to the workflow tools; it is narrow authority, not a reusable controller secret. Never print or publish it.

## Launch lifecycle

1. Entering an agent stage creates an `AmpLaunch`, per-launch capability, report nonce, encrypted canonical body, and queue job in the same workflow transaction.
2. Every delivery retry sends the exact stored body, launch event ID, and idempotency key.
3. The controller requests `launch.claim` before thread creation. Laravel grants the first current attempt only.
4. The controller creates one private `executor: "orb"` thread. It never recreates a claimed attempt.
5. `launch.acknowledged` binds the exact thread. A rejected/stale acknowledgement causes the controller to cancel the unprompted thread.
6. The controller confirms live context, checks the full transcript for `orc-stage-prompt:<launch-event>`, and appends the prompt only if absent.
7. Bounded reconciliation deliveries use distinct transport keys but the same canonical launch body. They recover the bound thread's prompt/monitor after controller restart and never call `createThread`.
8. A claim with no durable thread becomes `ambiguous`; it is never converted into a duplicate Orb.

Callbacks may arrive before the launch HTTP response. Business and delivery status are separate, so the later response cannot regress a claimed/launched/completed attempt.

## Proof and real Development flows

All modes start with `workflow_read_issue`, which uses native `gh` to read the current issue, paginated comments, and timeline-linked pull requests. GitHub content is untrusted data and is not authority to change workflow scope.

Proof modes publish one clearly labelled `workflow_post_test_comment` report and complete only their configured proof outcome. They retain normal Amp tools per user requirement but are instructed not to modify code. QA in workflow v2 uses `proof_complete` and is always displayed as integration proof—not code validation or approval.

Real Development:

1. reads GitHub and the checkout afresh;
2. implements only the outstanding bound issue work using normal tools and native authentication;
3. tests the change;
4. pushes deterministic branch `orc/stage-<stage-run-id>-attempt-<attempt>`;
5. creates or updates one open, same-repository, marker-bound pull request and never merges it;
6. calls `workflow_record_publication`, which verifies the PR through native `gh` before Laravel binds its identifiers;
7. publishes a substantive success/blocked report with `workflow_post_development_report`;
8. calls `workflow_complete(success|blocked)`.

Before a GitHub comment is posted, the worker acquires one persistent report-publication claim through the per-launch capability. Concurrent callers reconcile the authenticated user's paginated nonce marker rather than post twice. If a claimed publication has no visible comment, Orc reports ambiguity instead of risking a duplicate.

## Completion, safety, and cancellation

Laravel accepts completion only when the attempt is current, the thread matches, the outcome is configured, and required evidence is already bound. Real success requires the deterministic branch, verified open PR, and success report. Blocked requires a blocked report. Proof QA requires a proof report and `proof_complete`.

An explicit `workflow_complete` is the normal path. The controller's `agent.end`/monitor safety net gives one mode-specific corrective turn, then reports `stage.failed`; it never guesses an outcome. Reconciliation deliveries restore monitoring for up to one hour after acknowledgement.

Cancellation first closes Laravel's active slot and launch under locks, so late report/publication/completion calls are rejected and retained as rejected integration events. If the thread was already bound, a queued signed command also calls `thread.cancel()` on that exact thread. Cancellation cannot retract a Git push, comment, or PR operation already in flight; inspect GitHub after cancellation.

## Failure handling

- A non-retryable launch response while pending fails the launch, attempt, and workflow without a transition.
- Retryable transport failures use the same canonical body and keys. A persisted claim is redelivered for recovery rather than silently marked delivered.
- Exhausted unknown transport outcomes become `ambiguous`; Orc never blindly creates another Orb.
- `createThread` uncertainty and claim-without-thread are ambiguous and require manual inspection/cancellation.
- Prompt append is transcript-marker idempotent; report publication is persistent-claim/nonce idempotent.
- Exact callback event replay returns its stored response; reuse with another payload is rejected.
- Foreign-thread, wrong-mode, stale, cancelled, and racing callbacks cannot mutate the run.

## Verification checklist

1. Run `php artisan test --compact`, `vendor/bin/pint --test`, `composer validate --no-check-publish`, and `npm run build`.
2. Run the controller and global-worker Bun tests and bundle both against external `@ampcode/plugin`.
3. Confirm the trusted controller runtime file contains only the three documented names and has mode `0600`; never print values.
4. Confirm no obsolete Orc-owned GitHub or worker controller secrets remain in Amp project configuration.
5. Verify production migration status, queue process, `/register` 404, and authenticated workflow v2 UI.
6. Use issue `jacovanc/Orc#1` only for harmless integration proof. Do not use it for coding.
7. For real Development proof, require a separately designated small issue; confirm the new thread ID, branch, open PR, report, checks, and transition to proof-only QA.

## Existing proof evidence and current live gap

Production `RUN-0015` proved the older v1 Development→QA integration path on 2026-09-07 with two distinct fresh sandbox threads:

- Development thread: <https://ampcode.com/threads/T-01a07db9-0dc5-752c-a503-8be0cc5ce95d>
- Development proof report: <https://github.com/jacovanc/Orc/issues/1#issuecomment-5575693984>
- QA thread: <https://ampcode.com/threads/T-01a07db9-7853-759c-8e74-ebfdb89e0911>
- QA proof report: <https://github.com/jacovanc/Orc/issues/1#issuecomment-5575697936>

Those are orchestration proofs only. They did not validate code and must not be cited as Milestone 6 live coding proof. Milestone 6 is implemented and locally/mock verified, but no authorized real coding issue currently exists. Private-repository operation is not claimed.
