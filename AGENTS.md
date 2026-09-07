# Orc engineering guidance

## Repository authentication invariant

Orc assumes that each fresh Amp Orb already has the repository and GitHub access configured by the user through Amp's native authentication. Orc must never create, copy, inject, repair, or broaden GitHub credentials, tokens, credential helpers, or repository grants.

- Use the Orb's existing `git` and `gh` authentication for repository and GitHub operations.
- Treat missing read, push, issue, or pull-request access as a clear prerequisite failure or blocked outcome.
- Never add an Orc-owned GitHub token, `GH_TOKEN`, or `GITHUB_TOKEN` to Laravel, launch payloads, Amp project secrets, prompts, or workflow tools.
- Preserve the normal Amp agent toolset. Add narrowly stage-bound workflow tools; do not replace or suppress shell, editing, web, MCP, or other default tools as a security boundary.
- Never expose Laravel↔trusted-controller signing secrets to a coding Orb. Coding workers use only a per-launch capability bound to one stage run, exact Amp thread, permitted outcomes, and current workflow state.

Keep workflow authorization in Laravel: repository/operator allowlists, row locks, one active attempt, exact thread and stage binding, bounded outcomes, evidence identifiers, persistent event deduplication, cancellation, and stale/racing completion rejection.

Self-registration must remain opt-in and disabled in production. Registration and Amp launch authority are separate: a new account must never acquire launch authority unless an operator separately adds its normalized email and target repository to the deployment allowlists.
