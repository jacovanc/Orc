# Orc Project setup protocol v1

This is the authoritative installation contract for agent-assisted Orc Project pairing.

The deployed application serves this version publicly at `/docs/project-setup-v1`; generated setup prompts use that application-domain URL and do not depend on access to the Orc source repository.

## Operator flow

1. In Orc, create a Project with a display name and its canonical `owner/repository`. You do not need to find or enter an Amp project ID.
2. Open **Project → Settings** and copy the generated setup prompt.
3. Paste it into an already-authenticated agent thread opened in the Amp project you want to link.
4. The agent calls `orc_setup_project`. The first valid claim atomically binds the actual `AMP_PROJECT_ID` to this Project and connection; every retry and completion must match it. The tool claims the short-lived capability, verifies the controller source SHA-256, and writes only:
   - `.amp/plugins/orc-integration/index.ts`;
   - `.amp/runtime/orc-plugin.json` with mode `0600`;
   - the controller-generated `.amp/runtime/launch-webhook-url` with mode `0600`.
5. Amp's Plugin API has no programmatic reload operation. If requested, run **plugins: reload** once in the same thread, then tell the agent to continue. The second tool call submits the webhook directly over HTTPS and queues verification.
6. Refresh Orc. A successful harmless check shows the connection as **verified** and links its fresh verification thread. The trusted controller securely republishes its current durable webhook registration to Laravel whenever it loads. A missing/retired webhook fails closed; use **Generate new setup prompt** to pair a new immutable version.

The tool modifies no unrelated plugin. The personal `orc-worker` User Plugin must already be active; the presence of `orc_setup_project` proves that prerequisite. Global User Plugin publication is managed separately from project pairing.

## Capability and secret boundary

- Project creation allocates a durable connection UUID and distinct directional secrets before webhook registration, removing the old circular dependency.
- The setup capability expires after 20 minutes and is bound on first claim to one connection, the actual `AMP_PROJECT_ID`, owner, and Amp thread. It can be retried only by that same claimant. Reissue revokes prior unconsumed setup capabilities.
- The copied prompt necessarily carries the narrow setup capability because the Plugin API provides no secret-input channel for a custom tool. It is not a reusable launch/callback secret. The agent is told not to repeat it; API responses never reflect it, responses are `no-store`, and application code does not log it.
- The HTTPS claim response gives the setup tool—not a workflow prompt—the pinned controller and runtime material. The tool writes it only to gitignored, owner-readable runtime storage. Fresh workflow Orbs receive only per-stage capabilities, never directional controller secrets.
- Controller source is sent with and checked against a SHA-256 pinned into the setup row. Completion must attest that exact digest and an SSRF-guarded Amp webhook URL.
- Setup completion immediately queues the existing harmless fresh-Orb placement/native repository-read verification. Only a verified connection can launch workflows.

## GitHub access invariant

Orc uses only the Git and GitHub authentication that the user already configured for the target Amp project/Orb. Setup never creates, copies, injects, repairs, or broadens `GH_TOKEN`, `GITHUB_TOKEN`, credential helpers, repository grants, or project secrets. Missing native access is a verification failure and explicit prerequisite blocker.

## Consent scope

The generated prompt authorizes only personal Orc worker use, this project controller installation/update, webhook pairing, and one harmless verification. It does not authorize code changes, workflow starts, branch/PR creation, GitHub comments, merges, credential changes, or unrelated plugin edits.
