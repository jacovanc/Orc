# Orc

Orc is a Laravel-native workflow orchestrator for software delivery. It coordinates agent stages, QA loops, and human approval while keeping requirements, code, discussion, feedback, and reports in GitHub.

Milestones 1–5 are implemented as an authenticated, server-rendered MVP with a durable Laravel↔Amp integration. Read the [domain and architecture](docs/architecture.md) and [Amp integration runbook](docs/amp-integration.md) before extending the workflow engine.

## What is included

- Versioned, immutable workflow definitions with agent, human, and terminal stages.
- Seeded Development → QA → Human Review → Done graph, including QA and change-request loops.
- A central transactional `WorkflowEngine` with row locks, expected-attempt checks, idempotent duplicate completions, monotonic attempt numbers, and a database-enforced single-active-attempt slot.
- Append-only workflow events and stable historical stage attempts.
- Authenticated list, manual start, detail, status, stage graph, attempt table, event timeline, GitHub links, human actions, and cancellation pages.
- Durable queued Amp launches with bounded retries, stable idempotency keys, explicit ambiguous outcomes, persistent callback deduplication, and separate delivery/business state.
- A project-local Amp plugin that claims before creating one fresh private thread and Orb per agent attempt, exposes only harmless proof tools, and returns signed completions.
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
2. Reload the checked-in `.amp/plugins/orc-integration` plugin from an Amp-managed Orb. It writes its durable capability URL to gitignored `.amp/runtime/launch-webhook-url`.
3. Configure the matching Laravel variables listed in `.env.example`, set `QUEUE_CONNECTION=database`, and run a worker for the `amp-launches` queue.
4. Enable `AMP_INTEGRATION_ENABLED` only after both callback and launch directions are configured.

Never commit, log, or show the secrets, GitHub token, or webhook URL. Exact configuration, rotation, failure handling, and proof-agent boundaries are in [docs/amp-integration.md](docs/amp-integration.md).

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

Create an account through `/register`, then start a workflow using a matching GitHub repository, issue number, and issue URL.

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

The seed is idempotent and creates the immutable Development workflow v1. Production also needs a supervised `php artisan queue:work database --queue=amp-launches` process. Do not put GitHub or Amp credentials into source control.

Deployment status and exact verification evidence are recorded in [IMPLEMENTATION.md](IMPLEMENTATION.md).

## Current limitations

- Development and QA agents are harmless integration proofs. They read the bound issue, publish a clearly labelled test comment, and complete the stage; they do not modify code, branches, pull requests, labels, or issue state.
- A crash in the narrow interval after Amp creates a thread but before Laravel receives its thread ID leaves the launch claimed for manual reconciliation. Orc deliberately does not risk a duplicate Orb.
- Change feedback must be published manually on GitHub; Orc stores its URL only.
- There is no workflow editor. Definitions are seeded and versioned in code/database.
- Real Development/QA coding behavior begins in Milestone 6 or later.

## Design rules for later phases

1. Keep `WorkflowEngine` as the only mutation boundary.
2. Treat completion as an idempotent command addressed to an expected attempt ID.
3. Publish substantive agent output and human feedback to GitHub; store only external identifiers in Orc.
4. Add a new definition version instead of editing a definition already referenced by a run.
5. Preserve append-only event history and never repurpose an existing attempt number.
