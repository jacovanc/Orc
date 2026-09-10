# Orc roadmap after independent QA

## Required order

1. **Milestone 7 — independent substantive QA:** implemented and live-proven.
2. **Projects and Amp project routing:** implemented, including agent-assisted no-ID setup and explicit webhook-owner lifecycle verification/recovery guidance.
3. **Manual run controls:** pause an active agent and move a running, paused, or failed run to another actionable stage without rewriting attempt history; implemented as an audited run-level override, not a workflow editor.
4. **Post-approval Merge:** implemented as immutable workflow v4; GitHub queue support is implicit when repository policy requires it.
5. **Remaining hardening:** deeper observability, backup/restore exercises, and security review.
6. **Configurable workflow editor:** new immutable definition versions with validation and preview.
7. **Incremental onboarding:** setup protocol v1 now provides a short-lived agent-assisted connection prompt; broader account/project discovery remains deferred.

The earlier proposed Milestone 8 feature that would publish human feedback from Orc is **superseded**. Humans comment or review directly on GitHub, then manually choose an outcome in Orc without supplying a feedback URL. GitHub remains the sole home of feedback text.

Projects/routing does not include organizations, team permissions, billing, automatic GitHub credential grants, automatic Amp project creation, or a broad onboarding wizard. Setup v1 pairs one already-existing Project/Amp project; discovery and account-level onboarding remain deferred.
