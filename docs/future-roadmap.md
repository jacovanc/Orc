# Orc roadmap after independent QA

## Required order

1. **Milestone 7 — independent substantive QA:** implemented and live-proven.
2. **Projects and Amp project routing:** current implementation phase.
3. **Remaining hardening:** operational recovery, observability, backup/restore exercises, and security review.
4. **Configurable workflow editor:** new immutable definition versions with validation and preview.
5. **Deferred onboarding:** versioned setup documentation first, then a separately reviewed Connect Amp experience if supported APIs and threat modeling justify it.

The earlier proposed Milestone 8 feature that would publish human feedback from Orc is **superseded**. Humans comment or review directly on GitHub, then manually choose an outcome in Orc without supplying a feedback URL. GitHub remains the sole home of feedback text.

Projects/routing does not include organizations, team permissions, billing, automatic GitHub credential grants, automatic Amp project creation, or an onboarding wizard. Agent-assisted onboarding remains deferred until routing, hardening, and workflow configuration are stable.
