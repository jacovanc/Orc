# Milestone 7 design: independent substantive QA and remediation

Status: implemented and live-proven on 2026-09-08. Milestones 1–6 and workflow definitions v1/v2 remain immutable.

## Scope and non-goals

Milestone 7 adds a real, independent QA agent after real Development. QA starts in its own fresh private Amp thread and Orb, rereads the original issue and the exact Development pull request from GitHub, independently inspects and tests the proposed change, publishes a substantive report on that pull request, and explicitly completes `pass`, `fail`, or `blocked`.

This milestone does not add automatic merge, automatic human approval, implementation changes by QA, a workflow editor, or Milestone 8 feedback-publication automation. Human Review remains the release gate. Existing proof-only modes remain clearly labelled and cannot produce substantive QA outcomes.

## Immutable version 3 graph

```text
Real Development
  success ───────────────> Independent QA
  blocked ───────────────> Development Blocked Review (human)

Independent QA
  pass ──────────────────> Human Review
  fail ──────────────────> Real Development
  blocked ───────────────> QA Blocked Review (human)

Development Blocked Review
  retry ─────────────────> Real Development

QA Blocked Review
  retry ─────────────────> Independent QA

Human Review
  request_changes ───────> Real Development
  approve ───────────────> Done
```

Cancellation remains available at every active non-terminal stage. A QA `blocked` result is not a pass or fail and requires explicit operator intervention before another Orb can launch.

## GitHub source of truth and PR binding

GitHub owns the issue requirements and discussion, Development reports, branch and commits, pull request, reviews, CI/check results, QA findings, and remediation context. Laravel stores only orchestration state and identifiers already represented by the existing run/attempt/event schema.

The QA launch is bound to the most recent verified Development publication for that workflow run. The worker reads at call time:

- original issue body, comments, and linked pull requests;
- exact bound pull-request metadata, head/base repositories and branches, state, body, and head SHA;
- changed files and commit identifiers;
- pull-request issue comments, reviews, and inline review comments;
- commit check runs and combined status;
- prior Development and QA reports present in those GitHub discussions.

Laravel does not persist those bodies or results. It supplies and validates only the bound repository, issue, pull-request number/URL, and report comment identifier/URL.

Substantive QA reports are issue comments on the exact bound pull request, use an unguessable per-attempt marker, and are attested to Laravel before completion. Development and proof reports continue to target the original issue. Completion requires the attested report kind to exactly equal the requested QA outcome.

## Independent QA behavior

The QA agent uses GPT-5.6 Sol with the normal Amp default tools plus stage-bound workflow tools. It uses only the user's existing native Orb GitHub/git authentication; Orc never provisions, copies, injects, repairs, or broadens credentials.

QA must:

1. call the QA context tool and verify the exact bound PR;
2. fetch/check out that PR for inspection without pushing;
3. assess every acceptance criterion and relevant prior QA finding independently;
4. run checks appropriate to the change and inspect existing CI evidence;
5. make no implementation edits, commits, pushes, merges, approvals, or issue-state changes;
6. publish one substantive `pass`, `fail`, or `blocked` report on the bound PR;
7. call `workflow_complete` with the same outcome.

Normal tools mean the no-write rule is a stage instruction rather than a pretend sandbox security boundary. Laravel nevertheless refuses code-publication capability calls from QA and refuses QA completion without exact PR-bound evidence. Repository mutation authority remains the user's native Orb access; workflow authorization remains narrowly bounded at Laravel.

`fail` is used for a substantiated implementation or acceptance-criterion defect. `blocked` is reserved for an inability to reach a trustworthy verdict, such as missing repository access, unavailable required infrastructure, or irreconcilable test setup. A report must state the evidence and checks honestly; Orc never invents a failure to exercise the loop.

## Remediation flow

On QA `fail`, the central engine transactionally closes the QA attempt and starts a new numbered Development StageRun with a new launch, capability, thread, and Orb. The launch points to the existing bound PR. The Development agent rereads the issue, PR discussion, QA report, reviews, and CI from GitHub before changing code.

For workflow v3 remediation, Development reuses the verified existing PR head branch rather than opening parallel remediation PRs. The new attempt marker is added to that PR before publication is attested. This keeps one review surface while immutable StageRuns, report markers, Amp thread IDs, and append-only events preserve attempt history. Initial Development still uses `orc/stage-<stage-run-id>-attempt-<attempt>`.

Human `request_changes` follows the same remediation path without requiring a feedback URL or confirmation in Orc. The fresh Development agent rereads the bound pull request, reviews, inline comments, and discussion directly from GitHub.

## Capability and concurrency invariants

- One `AmpLaunch`, one capability, one bound thread, and one fresh Orb per agent StageRun.
- The trusted controller keeps global directional signing secrets; no global secret enters a coding or QA Orb.
- Each worker receives only its encrypted-at-rest per-launch capability, bound to the exact launch, current attempt, thread, mode, configured outcomes, report target, and publication rules.
- The central `WorkflowEngine` remains the sole mutation boundary and locks launch/run/attempt rows.
- Report publication uses a persistent first-writer claim and marker/author reconciliation; retries never knowingly duplicate a report.
- A QA report URL must identify an issue comment on the exact bound PR and match its numeric comment ID.
- A QA callback cannot attest an issue report, another PR, another repository, another thread, a stale attempt, or a report kind different from its outcome.
- Duplicate callback event IDs return the stored response; an event ID reused with different bytes is rejected.
- Racing `pass`, `fail`, and `blocked` completions serialize under the run lock. Only the first valid completion transitions; later outcomes are rejected as conflicting or stale.
- Cancellation closes the active slot before best-effort exact-thread cancellation, so late callbacks cannot transition the run. Already-started external side effects cannot be retracted.

## UI states

- Real Development, Substantive QA, and Integration Proof use distinct badges and explanatory copy.
- Running QA links the exact PR and Amp thread and states that it is independently inspecting/testing without modifying implementation.
- QA failure remains visible in immutable attempt history while the new Development attempt is active.
- QA blocked renders an explicit operator gate with retry QA and cancellation, never a misleading failed/passed status.
- Human Review in v3 states that substantive QA passed and still requires a separate human decision; v2 retains its proof-only warning.
- Attempt rows link the relevant PR, GitHub report, branch, and Amp thread.

## Verification and controlled acceptance

Automated coverage must prove:

- v1/v2 remain unchanged and v3 has the exact graph and modes;
- QA launch context carries the exact latest verified PR;
- pass/fail/blocked require matching PR reports and route correctly;
- issue comments, foreign PRs, mismatched comment IDs, wrong kinds, wrong threads, stale/cancelled attempts, and QA code-publication calls are rejected;
- duplicate reports/completions and racing outcomes are idempotent or rejected safely;
- QA fail creates a fresh Development launch with monotonic numbering, a distinct capability, and the existing PR branch;
- QA-blocked retry creates a fresh QA launch and thread slot;
- worker reads issue/PR/reviews/comments/files/commits/checks/status via native `gh`, publishes only to the bound PR, and never passes a GitHub token itself;
- controller agents extend the normal default mode with additive workflow tools and create `executor: "orb"` threads;
- representative running QA, failed-QA remediation, blocked QA, and Human Review pages render truthful states.

Live acceptance used transparent documentation-only issue [`jacovanc/Orc#6`](https://github.com/jacovanc/Orc/issues/6). Its public instructions explicitly required an incomplete first increment so QA could evaluate a real missing sentence without a hidden defect or fabricated result. Production `RUN-0020` followed the required sequence:

1. Development attempt 1 created open PR [`#7`](https://github.com/jacovanc/Orc/pull/7) in fresh thread [`T-01a081e1-a3a9-73bd-b4d4-ded469d926b9`](https://ampcode.com/threads/T-01a081e1-a3a9-73bd-b4d4-ded469d926b9) and disclosed the intentionally incomplete increment in its [substantive report](https://github.com/jacovanc/Orc/issues/6#issuecomment-5588613133).
2. Independent QA attempt 1 inspected exact head `ecb6f451eb8fae12d8e6c0e339021f934384ae04` in fresh thread [`T-01a081e5-0b33-72b0-8c6a-75c63c0d1abd`](https://ampcode.com/threads/T-01a081e5-0b33-72b0-8c6a-75c63c0d1abd), demonstrated the missing criterion, and published a substantive [fail report](https://github.com/jacovanc/Orc/pull/7#issuecomment-5588643300) without changing code.
3. Development attempt 2 started fresh thread [`T-01a081e7-4f1f-7118-93f8-81e902152265`](https://ampcode.com/threads/T-01a081e7-4f1f-7118-93f8-81e902152265), read the GitHub finding, updated the same PR to head `902cd159d9e01930123cfd08080af174be871d3c`, and published its [remediation report](https://github.com/jacovanc/Orc/issues/6#issuecomment-5588691193).
4. Independent QA attempt 2 started fresh thread [`T-01a081ea-f3ba-7168-b8af-7afd5adc8396`](https://ampcode.com/threads/T-01a081ea-f3ba-7168-b8af-7afd5adc8396), independently checked every criterion at that exact head, and published a substantive [pass report](https://github.com/jacovanc/Orc/pull/7#issuecomment-5588719376).

The four StageRuns each have one claimed, delivered, completed `AmpLaunch`, one distinct thread/Orb, one report claim, one attested report, and one accepted completion. The run is waiting at Human Review. PR #7 is open, non-draft, cleanly mergeable, unmerged, and changes only `docs/independent-qa-evidence.md`; Orc performed no merge or human approval.
