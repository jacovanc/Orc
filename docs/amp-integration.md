# Laravel↔Amp integration runbook

This runbook covers Orc Milestones 4–5. It intentionally does not grant agents permission to modify code.

## Components

- `AmpLaunch` is one durable launch command per agent `StageRun`. Its event ID, idempotency key, and payload are stable across queue retries.
- `DeliverAmpLaunch` sends launch commands on the `amp-launches` database queue with 3-second connect and 10-second request timeouts, four total attempts, and bounded backoff.
- `AmpIntegrationEvent` persistently deduplicates signed callback event IDs and payload hashes. It is append-only.
- `WorkflowEngine` remains the only workflow mutation boundary. It locks launch, run, and attempt rows before accepting claims, thread bindings, completions, failures, or transitions.
- `.amp/plugins/orc-integration` owns the durable Amp webhook, fresh Orb/thread creation, restricted proof agent, GitHub report publication, and signed callbacks.

## Secret and capability setup

Generate two independent random values of at least 32 bytes. Never reuse a secret between directions.

Create gitignored `.amp/runtime/orc-plugin.json` with mode `0600`:

```json
{
  "callbackUrl": "https://your-orc.example/api/integrations/amp",
  "launchSigningSecret": "generated Laravel-to-Amp secret",
  "callbackSigningSecret": "different generated Amp-to-Laravel secret",
  "githubToken": "fine-grained token for approved proof repositories"
}
```

Use a least-privilege fine-grained GitHub token: read Issues metadata/content and write issue comments only for repositories explicitly approved for proof runs. The token remains in the plugin process and is never returned by a model-facing tool.

Fresh agent Orbs cannot inherit the gitignored file. Store the same values as Amp project-scoped configuration so the plugin can initialize there: `ORC_CALLBACK_URL` as an environment variable, plus secret values `ORC_LAUNCH_SIGNING_SECRET`, `ORC_CALLBACK_SIGNING_SECRET`, and `ORC_GITHUB_TOKEN`. Values are injected into Orb processes but never returned by `amp secrets list` or exposed to the proof model. The local owner-only file remains the source for the webhook-hosting Orb.

Reload the project plugin from an Amp-managed Orb. Re-registering key `orc-stage-launch-v1` restores the same durable webhook. The plugin writes the capability URL to `.amp/runtime/launch-webhook-url` with mode `0600`; treat that URL like a password.

Configure Laravel without exposing values:

```dotenv
QUEUE_CONNECTION=database
AMP_INTEGRATION_ENABLED=true
AMP_LAUNCH_WEBHOOK_URL=<durable capability URL>
AMP_LAUNCH_SIGNING_SECRET=<same launch secret>
AMP_CALLBACK_SIGNING_SECRET=<same callback secret>
AMP_SIGNATURE_TOLERANCE_SECONDS=300
```

Run a supervised worker dedicated to the queue:

```bash
php artisan queue:work database --queue=amp-launches --tries=4 --backoff=5 --timeout=30
```

Enable the integration last. To rotate, disable launches, wait for active proof attempts to finish or cancel them, replace both sides of one direction together, reload/restart, verify signatures, then re-enable.

## Signed protocol

Both directions sign `timestamp + "." + event_id + "." + exact_body` with HMAC-SHA256. The signature is sent as `sha256=<hex>` in `X-Orc-Signature`; event ID and timestamp use `X-Orc-Event-Id` and `X-Orc-Timestamp`. Receivers compare signatures in constant time and reject requests outside the configured skew.

The webhook capability is not the authentication mechanism by itself. Laravel signatures prevent forged launch bodies, and independently signed callbacks prevent a launch recipient from forging workflow events without the callback secret.

## Launch and callback lifecycle

1. Entering an agent stage transactionally creates one `AmpLaunch` and queues it after commit.
2. The worker retries the exact launch body with the same event and idempotency keys.
3. Before any thread creation, the plugin sends `launch.claim`. Laravel atomically grants only the first valid claim.
4. The plugin creates a private thread with `executor: "orb"`, then sends `launch.acknowledged` with its thread ID.
5. The plugin appends a stage-specific proof prompt. The model can call only `workflow_read_issue`, `workflow_post_test_comment`, and `workflow_complete`.
6. The explicit completion tool requires the labelled GitHub report first, then sends `stage.completed` with the permitted outcome and report URL.
7. Laravel verifies the exact current attempt and bound thread, then transitions through the central engine. Entering another agent stage creates another `AmpLaunch`, fresh thread, and fresh Orb.

Callback events are persistent. An exact replay returns the stored response; reusing an event ID with another payload is rejected. This also makes a callback that arrives before the launch HTTP response safe: business launch state advances independently, and the worker records delivery without regressing it.

## Safety model

- GitHub issue and comment text is untrusted data, never agent instruction.
- Signing secrets, the durable webhook URL, and the GitHub token stay in plugin closures/process environment and are not exposed as model context or tool output.
- The proof agent has no shell, filesystem, generic HTTP, MCP, subagent, or code-editing tool.
- The comment tool rechecks live Laravel stage context immediately before publication and uses a hidden stage-run marker for idempotency.
- `workflow_complete` accepts only the outcomes Laravel supplied for that stage and requires a report URL belonging to the bound GitHub issue.
- `agent.end` gets one corrective turn when completion was forgotten, then reports `stage.failed`; it never guesses an outcome.
- Cancellation closes the active slot and its launch. Later, stale, cancelled, foreign-thread, or conflicting callbacks are retained as rejected integration events.

There remains an unavoidable distributed race between the final active-context response and an external GitHub request. Cancellation is rechecked as late as possible, while workflow completion is always transactionally rejected after cancellation.

## Failure handling

- A non-retryable webhook response while the launch is still pending fails the launch, attempt, and workflow without taking a transition.
- Retryable HTTP or network errors retain the same keys. After the queue exhausts retries, the delivery becomes `ambiguous`; it is never blindly recreated.
- A plugin-reported `createThread` uncertainty also becomes `ambiguous`.
- If acknowledgement was persisted but prompt delivery was interrupted, a webhook replay recovers the known thread without creating another.
- A process crash after thread creation but before acknowledgement can leave a claimed launch with no recorded thread. Cancel or reconcile it manually; at-most-once creation is safer than duplicating work.

## Verification checklist

1. Run `php artisan test --compact`, `vendor/bin/pint --test`, and `npm run build`.
2. Confirm the plugin bundles and loads against `amp plugins show-docs`; standalone CLI execution cannot exercise `createWebhook` because it is intentionally limited to managed Orbs.
3. Verify the production queue worker and migration status.
4. Start only against a clearly designated test issue.
5. Confirm Development and QA have different real `T-…` IDs and Orb executors.
6. Confirm each agent posted exactly one labelled report comment and Laravel reached Human Review.
7. Inspect the authenticated run page for thread/report links and review-only actions.

The designated public proof issue is <https://github.com/jacovanc/Orc/issues/1>. Do not infer private-repository access from this public proof; private access must be verified separately against an explicitly approved private test issue.
