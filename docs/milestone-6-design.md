# Milestone 6 design: real Development, proof-only QA

Status: implemented and live-proven with the user-authorized documentation issue `jacovanc/Orc#2`. The independent review's authorization, launch recovery, acknowledgement, truthful QA, immutable payload, report idempotency, controller-role, cancellation, registration, and lock-file findings were addressed, with its restrictive-tool and copied-credential recommendations superseded by the user's later native-auth/default-tools requirements.

## Scope and non-goals

Milestone 6 adds a real Development agent that starts in one fresh private Amp thread and Orb, reads the current GitHub issue context, changes code, runs bounded checks, pushes an attempt branch, opens or updates a pull request without merging it, publishes a substantive GitHub report, and explicitly completes `success` or `blocked`.

Milestone 6 does **not** add independent code-review/QA authority or a workflow editor. The following QA stage remains an orchestration proof only. Its name, outcome, reports, and UI must say that it does not validate code and is not approval. Human Review remains the release gate until Milestone 7 introduces independent real QA.

The Milestones 4–5 proof workflow remains immutable and available as version 1. Real Development belongs in a new definition version rather than mutating runs that already reference version 1.

## Proposed version 2 graph

```text
Development (real agent)
  success ───────────────> QA Integration Proof (not validation)
  blocked ───────────────> Development Blocked Review (human)

QA Integration Proof (not validation)
  proof_complete ────────> Human Review (human release gate)

Development Blocked Review
  retry ─────────────────> Development

Human Review
  request_changes ───────> Development
  approve ───────────────> Done
```

Cancellation remains available at every active non-terminal stage. A proof-only QA result is never named `pass` and never represented as substantive validation.

## Source-of-truth boundary

GitHub continues to own requirements, issue discussion, branches, commits, pull requests, code review, test reports, blocked explanations, and human feedback. Laravel stores only orchestration state and external identifiers:

- repository and issue identifiers;
- deterministic attempt branch name;
- pull-request number and URL;
- Amp thread/event IDs;
- report/feedback URLs, outcomes, and timestamps.

Laravel must not persist issue bodies, discussion text, generated patches, test output, report text, or hidden Development context. Every retry reads GitHub afresh.

## Stage configuration and dispatch

Version 2 stages use explicit immutable configuration:

- Development: `agent_mode: real_development`, outcomes `success|blocked`;
- QA Integration Proof: `agent_mode: proof_qa`, outcome `proof_complete`;
- Human stages: permitted action/outcome map only;
- terminal stage: no agent configuration.

The launch payload carries the mode, allowed outcomes, issue identifiers, attempt number, and identifiers from prior attempts such as an existing linked PR URL. The controller chooses a dedicated agent definition from `agent_mode`; it must not infer real coding permission from the stage name.

## Fresh-context Development flow

1. Laravel creates one durable launch for the active Development `StageRun` and uses the existing claim-before-create protocol.
2. The controller creates exactly one fresh private thread with `executor: "orb"` and binds it before prompting.
3. `workflow_read_issue` retrieves the issue body, current discussion, existing attempt report, and linked pull-request references from GitHub at call time. Returned GitHub content is explicitly untrusted data, not instructions outside the authorized issue task.
4. The agent verifies that its checkout is the bound GitHub repository, fetches that repository's current default branch, and bases the deterministic attempt branch on it before editing. A fresh Orb may initially have an Amp-hosted project `origin`; that remote must not be mistaken for the target GitHub remote.
5. The agent retains its normal Amp shell, edit, web, MCP, and related tools for repository inspection, implementation, and tests.
6. Native Orb `git` and `gh` authentication performs repository and GitHub operations. Orc neither supplies nor repairs access; missing access is a prerequisite blocker.
7. The agent uses the deterministic branch `orc/stage-<stage-run-id>-attempt-<attempt>`, pushes it only to an explicitly verified target GitHub remote, and creates or updates one marker-bound PR. It never merges, force-pushes, changes the issue state, or targets another repository.
8. `workflow_post_development_report` publishes a substantive issue comment linking the PR and recording check status. A nonce marker makes it idempotent.
9. `workflow_complete(success)` requires the bound PR and report. `workflow_complete(blocked)` requires a substantive blocked report and no fabricated success evidence.
10. Laravel transactionally validates the current attempt, exact thread, bounded outcome, same-repository PR/report URLs, and mode-specific evidence before transitioning.

An agent may update an existing PR only when it is explicitly linked to the same issue and bound to the current deterministic branch/attempt marker. It never merges a PR.

## Secret and tool boundary

The user explicitly requires normal Amp tools rather than a restricted sandbox. Supported plugin semantics are `extends: 'medium'` with `tools: { add: [...] }`; this preserves the built-in mode's defaults while adding exact workflow tools. Tests guard against accidentally replacing defaults with an explicit include list.

That full toolset means no broad Laravel↔Amp signing secret may enter the coding Orb through environment, files, prompt, or worker closure. The trusted controller alone reads those secrets from gitignored owner-only local configuration. Laravel instead issues an encrypted-at-rest random capability for each `AmpLaunch`; its public endpoint accepts that capability only for the exact bound thread, active attempt, allowed action/outcome, and authorized run. Completion, report, and PR identifiers still pass through the central transactional engine with persistent event deduplication.

The User Plugin exposes `workflow_read_issue`, `workflow_record_publication`, `workflow_post_development_report`, proof reporting, and `workflow_complete`. It has no GitHub credential configuration. Its GitHub calls use native `gh`, while ordinary agent Git uses native Orb Git authentication. Missing native access is reported as a prerequisite and is never “fixed” by Orc.

## Idempotency and concurrency

- One `AmpLaunch`, one claim, one thread, and one Orb per `StageRun` remain mandatory.
- Branch name and PR marker derive from immutable stage-run/attempt identifiers.
- Publish retries locate and update the marker-bound PR instead of creating another.
- Report retries locate the hidden stage-run marker instead of posting another comment.
- Completion callbacks retain persistent event deduplication and exact payload-hash checks.
- Cancelled, stale, foreign-thread, wrong-mode, and competing completion callbacks are recorded and rejected.
- A launch or push with an ambiguous network result is reconciled by deterministic identifiers before retry; it is never repeated blindly.

## Persistence proposal

Add nullable identifier fields to `stage_runs`:

- `github_branch`;
- `github_pull_request_number`;
- `github_pull_request_url`.

They are populated only through the central `WorkflowEngine` after validated worker callbacks. Append-only events record identifier changes. No GitHub content or test output is stored.

## UI requirements

- Show `Real Development` versus `Integration proof` badges from immutable stage mode.
- Link the active/historical Development attempt to its branch, PR, report, and Amp thread.
- Label QA everywhere as `QA Integration Proof — not code validation` and use `proof_complete`, never `pass`.
- State at Human Review that no independent real QA has occurred yet.
- Show blocked Development as waiting for a human retry/cancellation decision, not completed or approved.
- Keep Milestones 4–5 simulation/proof states explicit and unchanged.

## Test plan

Laravel regression coverage:

- version 1 remains immutable and behaves unchanged;
- version 2 dispatches explicit real/proof modes and bounded outcomes;
- success requires same-repository PR and report identifiers;
- blocked requires a blocked report and cannot masquerade as success;
- proof QA uses `proof_complete` and cannot submit `pass`;
- duplicate publish/completion, callback-before-response, stale attempt, cancellation, foreign thread, wrong mode, and racing outcomes;
- request-changes and blocked-retry loops retain monotonic attempt numbers and new deterministic branches;
- rendered badges, warnings, links, and Human Review language.

Worker/controller coverage with mocked GitHub and process boundaries:

- fresh issue/discussion/linked-PR reads;
- native GitHub access failure is surfaced as a prerequisite rather than triggering credential provisioning;
- additive `tools.add` preserves normal defaults and controller setup remains absent from unconfigured worker Orbs;
- deterministic branch/PR/report idempotency and ambiguous push reconciliation;
- no merge/force-push/cross-repository capability;
- credentials never appear in prompts, tool output, logs, or child command environment;
- `success|blocked` completion evidence and guarded `agent.end` behavior.

Live verification requires a clearly authorized, small real issue. The integration-test issue `jacovanc/Orc#1` is explicitly reserved for proof-only comments and must not be repurposed.

## Live acceptance

On 2026-09-08, user-authorized documentation issue `jacovanc/Orc#2` exercised workflow `RUN-0017`. Development ran in fresh sandbox thread `T-01a08098-ff9f-7367-af7a-99f1a0091ce4`, pushed deterministic branch `orc/stage-19-attempt-1`, opened (and did not merge) PR #3, published a substantive report, and completed `success`. Distinct sandbox thread `T-01a0809e-cbc1-767a-8ca5-2396c88216c5` then published an explicitly proof-only QA report and completed only `proof_complete`. The run stopped at Human Review.

The Development Orb initially inherited the Amp-hosted project remote and an obsolete empty base. It detected the mismatch, fetched GitHub `main`, rebased its documentation commit, pushed only the corrected branch to GitHub, and then created the PR. The controller instructions now require target checkout/default-branch verification before editing so later attempts avoid that recovery path. This public-repository acceptance does not prove private-repository access.

Orc and its agents left PR #3 open as required. The user subsequently merged it directly on GitHub; that external merge did not implicitly approve the Orc workflow. The user then separately approved Human Review, completing `RUN-0017` at Done.
