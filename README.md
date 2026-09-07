# Orc

Orc is a Laravel-native workflow orchestrator for software delivery. It coordinates agent stages, QA loops, and human approval while keeping requirements, code, discussion, feedback, and reports in GitHub.

Milestones 1–3 are implemented as an authenticated, server-rendered MVP. Read the [domain and architecture](docs/architecture.md) before extending the workflow engine.

## What is included

- Versioned, immutable workflow definitions with agent, human, and terminal stages.
- Seeded Development → QA → Human Review → Done graph, including QA and change-request loops.
- A central transactional `WorkflowEngine` with row locks, expected-attempt checks, idempotent duplicate completions, monotonic attempt numbers, and a database-enforced single-active-attempt slot.
- Append-only workflow events and stable historical stage attempts.
- Authenticated list, manual start, detail, status, stage graph, attempt table, event timeline, GitHub links, human actions, and cancellation pages.
- Explicit agent simulation controls so orchestration can be exercised without Amp.
- Laravel Breeze authentication and owner-scoped workflow access.

## Source-of-truth boundary

GitHub owns all substantive work context. Orc persists only:

- repository `owner/name`, issue number, and issue URL;
- workflow definition/stage/transition identifiers;
- run and attempt status, outcomes, and timestamps;
- nullable future Amp thread/event identifiers;
- event metadata containing orchestration identifiers and, for requested changes, the URL of feedback already published on GitHub.

Orc does **not** store issue bodies, prompts, generated code, reports, discussion, or review-feedback text.

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

Workflow coverage includes the seeded graph, forward transitions, QA and review loops, attempt numbering, idempotent duplicate completion, competing completion outcomes, stale attempts, invalid outcomes, cancellation, human-action restrictions, required GitHub feedback links, event immutability, ownership, request validation, and the database active-attempt constraint.

## Deployment

The MVP is deployed at <https://orc-production-trttyo.laravel.cloud/> from the `main` branch of `jacovanc/Orc`.

Laravel Cloud needs a database because workflow state and authentication are persistent. Configure an environment with:

- `APP_ENV=production`
- `APP_DEBUG=false`
- a generated `APP_KEY`
- the database variables provisioned by Laravel Cloud
- build command: `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci --audit false && npm run build`
- deploy command: `php artisan migrate --force && php artisan db:seed --force`

The seed is idempotent and creates the immutable Development workflow v1. Do not put GitHub or Amp credentials into source control.

Deployment status and exact verification evidence are recorded in [IMPLEMENTATION.md](IMPLEMENTATION.md).

## Current limitations

- Agent completion is simulated. No Amp thread/event is created.
- Simulation outcomes are not published to GitHub. A future adapter must publish reports/discussion there before completing the stage.
- Change feedback must be published manually on GitHub; Orc stores its URL only.
- There are no GitHub API calls, webhook ingestion, background workers, or workflow editor yet.
- Definitions are seeded and versioned in code/database rather than editable through the UI.

## Design rules for the next phase

1. Keep `WorkflowEngine` as the only mutation boundary.
2. Treat completion as an idempotent command addressed to an expected attempt ID.
3. Publish substantive agent output and human feedback to GitHub; store only external identifiers in Orc.
4. Add a new definition version instead of editing a definition already referenced by a run.
5. Preserve append-only event history and never repurpose an existing attempt number.
