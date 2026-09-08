# Orc

Orc is a Laravel-native workflow orchestrator for software delivery. It coordinates agent stages, QA loops, and human approval while keeping requirements, code, discussion, feedback, and reports in GitHub.

Milestones 1–6 are implemented as an authenticated, server-rendered application with durable Laravel↔Amp orchestration and real Development mode. Read the [domain and architecture](docs/architecture.md), [Amp integration runbook](docs/amp-integration.md), and [Milestone 6 design](docs/milestone-6-design.md) before extending the workflow engine.

Before allowing an administrator to start Amp-backed workflows, complete the
[operator access onboarding checklist](docs/operator-access-checklist.md).

## What is included

- Versioned, immutable workflow definitions with agent, human, and terminal stages.
- Seeded Development → QA → Human Review → Done graph, including QA and change-request loops.
- A central transactional `WorkflowEngine` with row locks, expected-attempt checks, idempotent duplicate completions, monotonic attempt numbers, and a database-enforced single-active-attempt slot.
- Append-only workflow events and stable historical stage attempts.
- Authenticated list, manual start, detail, status, stage graph, attempt table, event timeline, GitHub links, human actions, and cancellation pages.
- Durable queued Amp launches with bounded retries, stable idempotency keys, explicit ambiguous outcomes, persistent callback deduplication, and separate delivery/business state.
- A trusted project-local Amp controller plus a secretless global User Plugin worker. Agents retain normal tools and gain stage-bound workflow tools in one fresh private thread and Orb per attempt.
- Explicit agent simulation controls when the integration is disabled, so orchestration can still be exercised without Amp.
- Laravel Breeze authentication and owner-scoped workflow access.

## Source-of-truth boundary

GitHub owns all substantive work context. Orc persists only:

- repository `owner/name`, issue number, and issue URL;
- workflow definition/stage/transition identifiers;
- run and attempt status, outcomes, and timestamps;
- Amp thread/event identifiers, launch delivery state, callback idempotency facts, and lifecycle timestamps;
- event metadata containing orchestration identifiers and URLs of reports or feedback already published on GitHub.

Orc does **not** store issue bodies, prompts, generated code, reports, discussion, or review-feedback text.

## Amp integration setup

The integration is disabled by default. It requires a database queue worker and two independent, randomly generated HMAC secrets—one for Laravel→Amp launches and another for Amp→Laravel callbacks.

1. Put the callback base URL and secrets in the gitignored `.amp/runtime/orc-plugin.json` with owner-only permissions.
2. Install the `orc-worker` User Plugin. It adds stage-bound workflow tools and does not need or read project secrets.
3. Reload the checked-in `.amp/plugins/orc-integration` controller plugin from an Amp-managed Orb. It writes its durable capability URL to gitignored `.amp/runtime/launch-webhook-url`.
4. Configure the matching Laravel variables listed in `.env.example`, including fail-closed `AMP_ALLOWED_REPOSITORIES` and `AMP_ALLOWED_USER_EMAILS`, set `QUEUE_CONNECTION=database`, and run a worker for the `amp-launches` queue.
5. Enable `AMP_INTEGRATION_ENABLED` only after both callback and launch directions are configured.
6. Confirm every target repository is already accessible through native `git` and `gh` authentication in a fresh Orb. Orc never provisions, copies, or repairs GitHub access.

Never commit, log, or show the directional secrets, per-launch capabilities, or webhook URL. Orc does not own a GitHub token. Exact configuration, rotation, failure handling, and trust boundaries are in [docs/amp-integration.md](docs/amp-integration.md).

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

Self-registration is disabled by default. For local-only evaluation, explicitly set `REGISTRATION_ENABLED=true`, create an account through `/register`, then start a workflow using a matching GitHub repository, issue number, and issue URL. Never enable public registration in a shared Amp-backed deployment; even when registration is enabled, only accounts and repositories in the Amp allowlists may launch work.

For local asset development, run `npm run dev` alongside the Laravel server.

## Testing and quality checks

```bash
php artisan test --compact
vendor/bin/pint --test
npm run build
```

Workflow coverage includes the seeded graph, forward transitions, QA and review loops, attempt numbering, idempotent and competing completions, stale attempts, invalid outcomes, cancellation, human-action restrictions, required GitHub feedback links, event immutability, ownership, request validation, and the database active-attempt constraint. Integration coverage additionally exercises signed callbacks, stable launch retries, persistent claims and callback deduplication, callback-before-response ordering, foreign threads, cancelled/stale attempts, agent failures, and permanent versus ambiguous delivery outcomes.

## Deployment

The MVP is deployed at <https://orc-production-trttyo.laravel.cloud/> from the `main` branch of `jacovanc/Orc`.

Laravel Cloud needs a database because workflow state and authentication are persistent. Configure an environment with:

- `APP_ENV=production`
- `APP_DEBUG=false`
- a generated `APP_KEY`
- the database variables provisioned by Laravel Cloud
- build command: `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci --audit false && npm run build`
- deploy command: `php artisan migrate --force && php artisan db:seed --force`

The seed is idempotent and preserves immutable workflow v1 while creating real-Development workflow v2. Production also needs a supervised `php artisan queue:work database --queue=amp-launches` process. Do not put Amp credentials into source control; GitHub access remains native user-configured Orb state, never Orc configuration.

Deployment status and exact verification evidence are recorded in [IMPLEMENTATION.md](IMPLEMENTATION.md).

## Current limitations

- Workflow definition v1 retains the harmless Development/QA integration proof. Definition v2 adds real Development and keeps QA explicitly proof-only until Milestone 7.
- Self-registration is source-default-disabled and production returns 404 for `/register`. Amp launches independently fail closed unless both the repository and initiating user are explicitly allowlisted, so enabling registration alone cannot grant access to the owner's Amp account.
- Agents retain normal Amp shell, editing, web, MCP, and other default tools. Orc adds workflow tools and enforces authority at Laravel's orchestration boundary rather than by suppressing tools.
- A per-launch capability replaces broad callback credentials in fresh coding Orbs. It is bound to one attempt/thread and cannot grant repository access.
- Reports use an unguessable per-launch nonce, native GitHub author checks, paginated reconciliation, and durable Laravel attestation before completion.
- A crash in the narrow interval after Amp creates a thread but before Laravel receives its thread ID leaves the launch claimed for manual reconciliation. Orc deliberately does not risk a duplicate Orb.
- Change feedback must be published manually on GitHub; Orc stores its URL only.
- There is no workflow editor. Definitions are seeded and versioned in code/database.
- Real Development has a controlled public-repository acceptance on documentation issue `jacovanc/Orc#2`; its pull request remains open for Human Review. This does not prove private-repository operation. Independent real QA remains Milestone 7 work.

## Design rules for later phases

1. Keep `WorkflowEngine` as the only mutation boundary.
2. Treat completion as an idempotent command addressed to an expected attempt ID.
3. Publish substantive agent output and human feedback to GitHub; store only external identifiers in Orc.
4. Add a new definition version instead of editing a definition already referenced by a run.
5. Preserve append-only event history and never repurpose an existing attempt number.
