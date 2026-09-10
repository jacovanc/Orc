# Merge stage design

Status: implemented as immutable workflow v4. Workflow definitions v1–v3 and their existing runs remain immutable.

## Purpose and boundary

Workflow v4 replaces Human Review's direct `approve → Done` transition with `approve → Merge`. A fresh GPT-5.6 Sol agent then merges the exact pull request using the user's existing native Orb GitHub authentication. Orc never provisions GitHub credentials, bypasses repository protections, grants approval, or introduces a dedicated GitHub merge tool. Normal `gh pr merge` behavior automatically uses GitHub's merge queue when the repository requires one; Orc neither configures nor models the queue separately.

GitHub remains authoritative for the pull request, reviewed head, checks, reviews, conflict-resolution commits, merge state, merge commit, and reports. Laravel stores only the identifiers and orchestration evidence needed to bind a stage completion.

## Immutable version 4 graph

```text
Real Development
  success ───────────────> Independent QA
  blocked ───────────────> Development Blocked Review

Independent QA
  pass ──────────────────> Human Review
  fail ──────────────────> Real Development
  blocked ───────────────> QA Blocked Review

Human Review
  request_changes ───────> Real Development
  approve ───────────────> Merge

Merge
  merged ────────────────> Done
  requires_review ───────> Independent QA
  blocked ───────────────> Merge Blocked Review

Merge Blocked Review
  retry ─────────────────> Merge
```

Development/QA blocked retries remain identical to v3.

## Merge policy

The Merge agent first rereads the issue, exact PR, reviews, inline comments, QA reports, checks, base branch, current head, and repository mergeability. The QA attempt that led to Human Review records the exact PR head SHA. Merge starts only when that reviewed head exists, and it refuses to merge if the PR head changed before the agent began.

- **Clean:** use `gh pr merge --auto --match-head-commit <current-head>` and report `merged` only after GitHub says the PR is actually merged.
- **Mechanical conflict:** resolve only behavior-preserving conflicts (for example adjacent documentation, deterministic generated/lockfile output, or unambiguous rename/import alignment), run appropriate checks, push to the existing PR branch, then merge. The report explains the resolution.
- **Material conflict:** resolve and push, but do not merge. Report `requires_review`, which routes through fresh independent QA and Human Review.
- **Blocked:** use for changed approved head, unavailable native access, policy/check failure, unresolved or uncertain conflicts, a closed/replaced PR, timeout while waiting for an optional merge queue, or any state where an actual merge cannot be verified safely.

Only one automatic material-conflict review cycle is permitted for a run. Laravel counts prior accepted `requires_review` Merge attempts and removes/rejects that outcome afterward. A later material conflict routes to Merge Blocked Review instead of creating an endless QA/Human/Merge loop. The human may retry after intervention or use the existing audited manual controls.

## Evidence and authorization

- QA's native GitHub read records the exact tested PR head SHA with its marker-bound PR report.
- A Merge launch carries the exact prior PR and latest passing QA head SHA plus the number of prior material-conflict review cycles.
- The stage-scoped `workflow_complete` capability remains the only added worker capability. It verifies native-user-authored marker-bound PR reports and live pull-request evidence using native `gh` before sending bounded identifiers to Laravel.
- `merged` additionally requires the exact PR number/URL, final head SHA, and GitHub merge commit SHA. Laravel records a `stage.merge_verified` event before allowing `Done`.
- `requires_review` requires an open exact PR, a new head SHA different from the approved SHA, and no prior accepted material-conflict review cycle.
- Duplicate/racing reports, merge evidence, and completions use the existing persistent event deduplication and run/attempt locks. A callback after stop, cancellation, manual movement, or another outcome is stale.
- Existing project/connection snapshots remain immutable. Controller protocol v2 advertises Merge support; v4 is unavailable on older connections until the owner pairs an updated controller version.

## UI

- Human Review says approval starts a merge agent and does not itself merge.
- Merge shows the exact PR, reviewed head, Amp thread, bounded conflict policy, and optional-queue behavior.
- Merge Blocked Review explains that no merge occurred and offers a fresh retry without claiming QA failure.
- Done shows the verified merge commit when available.
- Attempts/timeline retain conflict-resolution, report, thread, and merge identifiers.

## Non-goals

- Configuring branch protections, required checks, auto-merge, or merge queues.
- Bypassing repository policy or administrator controls.
- Automatically classifying a second material conflict into another review loop.
- Merging existing v1–v3 workflows after their historical Human Review approval.
- A workflow editor or arbitrary stage scripting.
