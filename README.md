# Orc

Orc is a Laravel-native workflow orchestrator for software delivery. It coordinates agent stages, QA loops, and human approval while keeping requirements, code, discussion, feedback, and reports in GitHub.

Milestones 1–7 and personal Projects are implemented as an authenticated, server-rendered application with durable Laravel↔Amp orchestration, real Development, independent substantive QA, and verified per-Amp-project routing. Read the [domain and architecture](docs/architecture.md), [Amp integration runbook](docs/amp-integration.md), and [Project routing design](docs/project-routing-design.md) before extending the workflow engine.

Before allowing an administrator to start Amp-backed workflows, complete the
[operator access onboarding checklist](docs/operator-access-checklist.md). The next architecture phase is specified in the [Project management and Amp routing design](docs/project-routing-design.md); sequencing and explicitly deferred work are in the [roadmap](docs/future-roadmap.md).

## What is included

- Versioned, immutable workflow definitions with agent, human, and terminal stages.
- Seeded Development → QA → Human Review → Done graph, including QA and change-request loops.
- A central transactional `WorkflowEngine` with row locks, expected-attempt checks, idempotent duplicate completions, monotonic attempt numbers, and a database-enforced single-active-attempt slot.
- Append-only workflow events and stable historical stage attempts.
- Authenticated list, manual start, detail, status, stage graph, attempt table, event timeline, GitHub links, human actions, and cancellation pages.
- Durable queued Amp launches with bounded retries, stable idempotency keys, explicit ambiguous outcomes, persistent callback deduplication, and separate delivery/business state.
- A trusted project-local Amp controller plus a secretless global User Plugin worker. Agents retain normal tools and gain stage-bound workflow tools in one fresh private thread and Orb per attempt.
- Independent QA bound to the exact Development pull request, including fresh issue/PR/review/CI reads, substantive PR reports, fail-to-remediation loops, and a human gate for blocked verdicts.
- Explicit agent simulation controls when the integration is disabled, so orchestration can still be exercised without Amp.
- Laravel Breeze authentication and owner-scoped workflow access.
- Personal Projects with a canonical GitHub repository, explicit Amp project identity, immutable versioned controller connections, per-project run/start/settings pages, and an all-project run overview.

## Source-of-truth boundary

GitHub owns all substantive work context. Orc persists only:

- repository `owner/name`, issue number, and issue URL;
- workflow definition/stage/transition identifiers;
- run and attempt status, outcomes, and timestamps;
- Amp thread/event identifiers, launch delivery state, callback idempotency facts, and lifecycle timestamps;
- event metadata containing orchestration identifiers and URLs of agent reports published on GitHub.

Orc does **not** store issue bodies, prompts, generated code, reports, discussion, or review-feedback text.

## Amp integration setup

The integration is disabled by default. It requires a database queue worker and one trusted controller registered inside every Amp project Orc should route to.

1. Create the Orc Project with a display name and canonical `owner/repository`. You do not need to find an Amp project ID; Orc immediately preallocates an immutable pending connection identity.
2. Open Project Settings and copy its short-lived, self-bootstrapping setup prompt into an already-authenticated agent in that exact Amp project. No Orc plugin or tool needs to exist yet.
3. The agent verifies Orc's immutable public worker artifact and publishes it—without touching unrelated plugins—to your Personal Plugins repository. Run **plugins: reload** when asked; the worker is then available to this and future fresh Orbs.
4. The resulting `orc_setup_project` tool binds the actual `AMP_PROJECT_ID` from that selected project, installs the SHA-256-pinned project controller, exchanges directional secrets and the webhook over HTTPS, and queues harmless verification. It neither overwrites unrelated plugins nor handles GitHub credentials. Run the additional controller **plugins: reload** when requested. No webhook URL or signing secret is copied by hand.
5. Refresh Orc for the fresh-Orb placement and native `gh` read result. Repeat independently for every Project/Amp project.
6. Set `QUEUE_CONNECTION=database`, run a worker for `amp-launches`, and enable `AMP_INTEGRATION_ENABLED` only after application setup. No global GitHub token, repository allowlist, or mutable-email authority is used.

Never commit or log directional secrets, per-launch capabilities, or webhook URLs. Orc does not own a GitHub token. Exact setup steps are in [Project setup protocol v1](docs/project-setup-v1.md); configuration, rotation, failure handling, and trust boundaries are in [docs/amp-integration.md](docs/amp-integration.md).

## Local setup

Requirements: PHP 8.2+, Composer, Node.js 20+, npm, and SQLite (or another Laravel-supported database).

```bash
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Self-registration is disabled by default. For local-only evaluation, explicitly set `REGISTRATION_ENABLED=true` and create an account through `/register`. New accounts always have `can_trigger_amp=false`; an operator must separately grant that immutable account permission before it can configure a controller or launch an agent. Never enable public registration in a shared Amp-backed deployment.

For local asset development, run `npm run dev` alongside the Laravel server.

## Testing and quality checks

```bash
php artisan test --compact
vendor/bin/pint --test
npm run build
```

Workflow coverage includes the seeded graph, forward transitions, QA and review loops, attempt numbering, idempotent and competing completions, stale attempts, invalid outcomes, cancellation, human-action restrictions, link-free human approval/change requests, event immutability, ownership, request validation, and the database active-attempt constraint. Integration coverage additionally exercises signed callbacks, stable launch retries, persistent claims and callback deduplication, callback-before-response ordering, foreign threads, cancelled/stale attempts, agent failures, and permanent versus ambiguous delivery outcomes.

## Deployment

The MVP is deployed at <https://orc-production-trttyo.laravel.cloud/> from the `main` branch of `jacovanc/Orc`.

Laravel Cloud needs a database because workflow state and authentication are persistent. Configure an environment with:

- `APP_ENV=production`
- `APP_DEBUG=false`
- a generated `APP_KEY`
- the database variables provisioned by Laravel Cloud
- build command: `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci --audit false && npm run build`
- deploy command: `php artisan migrate --force && php artisan db:seed --force`

The seed is idempotent and preserves immutable workflows v1/v2 while creating independent-QA workflow v3. Production also needs a supervised `php artisan queue:work database --queue=amp-launches` process. Do not put Amp credentials into source control; GitHub access remains native user-configured Orb state, never Orc configuration.

Deployment status and exact verification evidence are recorded in [IMPLEMENTATION.md](IMPLEMENTATION.md).

## Current limitations

- Workflow definition v1 retains the harmless Development/QA integration proof. Definition v2 retains live-proven real Development with explicitly proof-only QA. Definition v3 adds independent substantive QA with `pass|fail|blocked` and remediation/operator loops.
- Self-registration is source-default-disabled and production returns 404 for `/register`. Amp launches independently require the authenticated owner's immutable `can_trigger_amp` permission and a verified Project connection, so registration or profile-email changes cannot grant access to the owner's Amp account.
- Agents retain normal Amp shell, editing, web, MCP, and other default tools. Orc adds workflow tools and enforces authority at Laravel's orchestration boundary rather than by suppressing tools.
- A per-launch capability replaces broad callback credentials in fresh coding Orbs. It is bound to one attempt/thread and cannot grant repository access.
- Reports use an unguessable per-launch nonce, native GitHub author and exact-target checks, and durable Laravel attestation before completion.
- A crash in the narrow interval after Amp creates a thread but before Laravel receives its thread ID leaves the launch claimed for manual reconciliation. Orc deliberately does not risk a duplicate Orb.
- Any change feedback is published manually on GitHub. Orc requires no feedback URL or confirmation before a human chooses Request Changes or Approve.
- Project connection changes create new versions. Existing runs, retries, QA, reconciliation, and cancellation stay bound to their original connection. A real second-project placement proof still requires a second user-configured Amp project.
- There is no workflow editor. Definitions are seeded and versioned in code/database.
- Real Development has a completed controlled public-repository acceptance on documentation issue `jacovanc/Orc#2`; Orc left its pull request open, the user merged it directly on GitHub, then separately approved the Orc Human Review. This does not prove private-repository operation.
- QA has normal tools for inspection/testing but no Orc publication capability and is instructed never to change/push implementation. Native repository permissions are user-owned, so this is a workflow rule rather than a fake sandbox boundary.

## Design rules for later phases

1. Keep `WorkflowEngine` as the only mutation boundary.
2. Treat completion as an idempotent command addressed to an expected attempt ID.
3. Publish substantive agent output and human feedback to GitHub; Orc stores only the external identifiers required for orchestration.
4. Add a new definition version instead of editing a definition already referenced by a run.
5. Preserve append-only event history and never repurpose an existing attempt number.
