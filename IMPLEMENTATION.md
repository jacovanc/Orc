# Orc MVP implementation record

## Scope

Milestones 1–3: authenticated Laravel application, versioned workflow domain, transactional workflow engine, seeded Development workflow, operational UI, tests, and deployment attempt.

## Work log

- 2026-09-07: Documented domain boundary, data model, seeded graph, engine invariants, and phase limitations before application implementation.
- 2026-09-07: Confirmed the Laravel Cloud API credential is present and valid without exposing its value. Installed Cloud CLI v0.5 locally; that version does not support the newer non-interactive environment-token flow, so authenticated Cloud setup uses the documented REST API.
- 2026-09-07: Inspected Amp plugins after loading the plugin skill. Workspace plugin access is active, but no Orc/Amp orchestration integration plugin is installed; real Amp integration remains blocked and out of scope for this phase.
- 2026-09-07: Implemented Laravel 12 and Breeze authentication, six-table workflow persistence, the immutable seeded graph, centralized transactional engine, authenticated HTTP actions, and the server-rendered UI.
- 2026-09-07: Added regression coverage for transitions, both loop paths, numbered attempts, idempotency, competing outcomes, database active-attempt protection, invalid/stale/cancelled completions, human actions, append-only events, URL validation, and ownership.
- 2026-09-07: Exercised Development → QA → Human Review → Done through the rendered UI with a real browser. Browser console/errors were empty. Visual inspection passed after removing an unnecessary timeline viewport cap.

## Verification

- `php artisan test --compact`: **42 passed, 148 assertions**.
- `vendor/bin/pint --test`: **68 files passed**.
- `npm run build`: **production assets built successfully**.
- `php artisan migrate:fresh --seed`: **all migrations and Development workflow seed succeeded**.
- Browser screenshots: `.amp/in/artifacts/workflow-agent-active.png`, `.amp/in/artifacts/workflow-human-review.png`, and `.amp/in/artifacts/workflow-completed.png`.

## Deployment

Production deployment is blocked before environment provisioning:

- The Laravel Cloud API credential is present, valid, and returned a successful authenticated response.
- Laravel Cloud requires the application source to be published through a supported GitHub, GitLab, or Bitbucket repository; the Amp Git remote is not a supported deployment source.
- The authenticated GitHub integration can administer existing repositories but returned `Resource not accessible by integration (createRepository)` when asked to create a private `jacovanc/orc` repository.
- Existing repositories belong to unrelated applications and were deliberately not overwritten or repurposed.
- No GitHub repository, Laravel Cloud application, or Laravel Cloud environment was created, and there is therefore no production deployment URL.

Deployment can continue after a GitHub repository is created for Orc (or the integration receives repository-creation permission): publish this branch, connect that repository in Laravel Cloud, provision a database, configure the environment described in `README.md`, deploy, then run `php artisan migrate --force` and `php artisan db:seed --force`.
