# Orc MVP implementation record

## Scope

Milestones 1–3: authenticated Laravel application, versioned workflow domain, transactional workflow engine, seeded Development workflow, operational UI, tests, and production deployment.

## Work log

- 2026-09-07: Documented domain boundary, data model, seeded graph, engine invariants, and phase limitations before application implementation.
- 2026-09-07: Confirmed the Laravel Cloud API credential is present and valid without exposing its value. Installed Cloud CLI v0.5 locally; that version does not support the newer non-interactive environment-token flow, so authenticated Cloud setup uses the documented REST API.
- 2026-09-07: Inspected Amp plugins after loading the plugin skill. Workspace plugin access is active, but no Orc/Amp orchestration integration plugin is installed; real Amp integration remains blocked and out of scope for this phase.
- 2026-09-07: Implemented Laravel 12 and Breeze authentication, six-table workflow persistence, the immutable seeded graph, centralized transactional engine, authenticated HTTP actions, and the server-rendered UI.
- 2026-09-07: Added regression coverage for transitions, both loop paths, numbered attempts, idempotency, competing outcomes, database active-attempt protection, invalid/stale/cancelled completions, human actions, append-only events, URL validation, and ownership.
- 2026-09-07: Exercised Development → QA → Human Review → Done through the rendered UI with a real browser. Browser console/errors were empty. Visual inspection passed after removing an unnecessary timeline viewport cap.
- 2026-09-07: Published commit `81493c8bf81fc3b1a53f5e65eeb13a767e7863b1` to `jacovanc/Orc` on GitHub, provisioned the Orc application and production environment on Laravel Cloud, attached a private persistent MySQL database, and deployed successfully.

## Verification

- `php artisan test --compact`: **42 passed, 148 assertions**.
- `vendor/bin/pint --test`: **68 files passed**.
- `npm run build`: **production assets built successfully**.
- `php artisan migrate:fresh --seed`: **all migrations and Development workflow seed succeeded**.
- Browser screenshots: `.amp/in/artifacts/workflow-agent-active.png`, `.amp/in/artifacts/workflow-human-review.png`, and `.amp/in/artifacts/workflow-completed.png`.
- Laravel Cloud deployment `depl-a2b0d072-0c99-403d-95c8-f7365f9f534f`: **`deployment.succeeded`**; source commit matched `81493c8bf81fc3b1a53f5e65eeb13a767e7863b1`.
- Production `php artisan migrate:status --no-interaction`: **command succeeded with exit code 0** and all four migrations reported `Ran`.
- Production HTTP smoke test: `/`, `/register`, and `/login` returned **200**; unauthenticated `/workflows` returned **302** to `/login`; compiled CSS and JavaScript returned **200**.
- Production browser console/errors were empty. `.amp/in/artifacts/orc-production-home.png` was captured from the deployed application and visually inspected with no layout or styling defects.

## Deployment

Production is live at <https://orc-production-trttyo.laravel.cloud/>.

- Source: public GitHub repository `jacovanc/Orc`, branch `main`.
- Laravel Cloud application: `app-a2b0cf1b-4c0d-4cde-9370-d7f30f4516d0` in `eu-west-2`.
- Environment: `env-a2b0cf1c-fca2-46f1-992a-19399332cb4c`, PHP 8.4, Node.js 22, push-to-deploy enabled.
- Database: private Laravel MySQL 8.4 cluster `orc-production`, 512 MB flexible compute, 5 GB storage, scheduled snapshots with two-day retention, and a 60-second idle suspend interval.
- Environment variables include a Laravel Cloud-managed `APP_KEY`, production mode with debug disabled, the canonical application URL, and stderr logging. Secret values were never printed or committed.
- Build uses optimized production Composer dependencies and compiled Vite assets. Deploy runs `php artisan migrate --force && php artisan db:seed --force`.
