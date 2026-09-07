# Orc implementation record

## Scope

Milestones 1–5: authenticated Laravel application, versioned workflow domain, transactional workflow engine, seeded Development workflow, durable Laravel↔Amp proof integration, operational UI, tests, and production deployment.

## Work log

- 2026-09-07: Documented domain boundary, data model, seeded graph, engine invariants, and phase limitations before application implementation.
- 2026-09-07: Confirmed the Laravel Cloud API credential is present and valid without exposing its value. Installed Cloud CLI v0.5 locally; that version does not support the newer non-interactive environment-token flow, so authenticated Cloud setup uses the documented REST API.
- 2026-09-07: Inspected Amp plugins after loading the plugin skill. Workspace plugin access is active, but no Orc/Amp orchestration integration plugin is installed; real Amp integration remains blocked and out of scope for this phase.
- 2026-09-07: Implemented Laravel 12 and Breeze authentication, six-table workflow persistence, the immutable seeded graph, centralized transactional engine, authenticated HTTP actions, and the server-rendered UI.
- 2026-09-07: Added regression coverage for transitions, both loop paths, numbered attempts, idempotency, competing outcomes, database active-attempt protection, invalid/stale/cancelled completions, human actions, append-only events, URL validation, and ownership.
- 2026-09-07: Exercised Development → QA → Human Review → Done through the rendered UI with a real browser. Browser console/errors were empty. Visual inspection passed after removing an unnecessary timeline viewport cap.
- 2026-09-07: Published commit `81493c8bf81fc3b1a53f5e65eeb13a767e7863b1` to `jacovanc/Orc` on GitHub, provisioned the Orc application and production environment on Laravel Cloud, attached a private persistent MySQL database, and deployed successfully.
- 2026-09-07: Began Milestones 4–5 by inspecting the installed Amp Plugin API and durable webhook contract after loading the plugin and webhook skills. Documented the signed, claim-before-create, at-most-once launch protocol before implementation. GitHub access includes private repositories, but no issue was clearly designated as safe for integration comments; external proof remains pending an explicit repository/issue selection.
- 2026-09-07: Created the user-authorized public proof issue `jacovanc/Orc#1`, implemented durable queued launches and persistent callbacks, and added the restricted project-local Amp proof plugin. The model receives neither directional secret, the webhook capability, nor a GitHub token.
- 2026-09-07: Stored the existing GitHub credential only in the owner-readable, gitignored plugin runtime configuration. API reads returned HTTP 200 for the approved public proof issue and a private repository issue, proving the configured credential—not merely anonymous public access—has private read access. No private issue was modified and no spawned-agent private proof is claimed.
- 2026-09-07: Added integration UI states for dispatch, delivery, launch ambiguity/failure, real thread links, and GitHub report links. Simulation controls now appear only when Amp integration is disabled.
- 2026-09-07: Deployed integration commit `ff5732952ed511ffa2fced9d4fa00a7e008782cd` without destructive database changes. Provisioned one supervised Laravel Cloud database queue worker dedicated to `amp-launches` and configured the separate directional secrets with integration disabled until the durable Amp webhook is activated.
- 2026-09-07: Live proof remains pending because this already-running thread has no `load_plugin`/`reload_plugins` tool and standalone `amp plugins` execution is correctly denied `amp.createWebhook`. The user was asked to run `plugins: reload` in the same thread; no webhook capability was exposed or fabricated.

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
- Milestones 4–5 integration tests: **12 passed, 66 assertions**; full suite after integration: **55 passed, 222 assertions**.
- Amp plugin bundle check: `bun build ... --external @ampcode/plugin --target bun` succeeded. `amp plugins list` parsed the plugin and reached the expected managed-Orb-only `createWebhook` guard in standalone CLI mode.
- Rendered integration states passed browser console/error checks and visual inspection: `.amp/in/artifacts/amp-launch-pending.png` and `.amp/in/artifacts/amp-proof-human-review.png`.
- Laravel Cloud deployment `depl-a2b0ead3-da4a-4a12-9e4d-b82fb1a5c500`: **`deployment.succeeded`**, source commit `ff5732952ed511ffa2fced9d4fa00a7e008782cd`.
- Laravel Cloud background process `process-a2b0eb69-ac71-4ef1-806e-93a70c442d92`: one database worker on queue `amp-launches`, four tries, bounded backoff, and a 30-second worker timeout.

## Deployment

Production is live at <https://orc-production-trttyo.laravel.cloud/>.

- Source: public GitHub repository `jacovanc/Orc`, branch `main`.
- Laravel Cloud application: `app-a2b0cf1b-4c0d-4cde-9370-d7f30f4516d0` in `eu-west-2`.
- Environment: `env-a2b0cf1c-fca2-46f1-992a-19399332cb4c`, PHP 8.4, Node.js 22, push-to-deploy enabled.
- Database: private Laravel MySQL 8.4 cluster `orc-production`, 512 MB flexible compute, 5 GB storage, scheduled snapshots with two-day retention, and a 60-second idle suspend interval.
- Environment variables include a Laravel Cloud-managed `APP_KEY`, production mode with debug disabled, the canonical application URL, and stderr logging. Secret values were never printed or committed.
- Build uses optimized production Composer dependencies and compiled Vite assets. Deploy runs `php artisan migrate --force && php artisan db:seed --force`.
- Integration variables and independent secrets are configured without exposing their values. `AMP_INTEGRATION_ENABLED` remains false until plugin activation supplies the durable capability URL; enabling before that would create definitively failed launches.
