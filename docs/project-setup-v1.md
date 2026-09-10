# Orc Project setup protocol v1

This is the authoritative installation contract for agent-assisted Orc Project pairing.

The deployed application serves this version publicly at `/docs/project-setup-v1`; generated setup prompts use that application-domain URL and do not depend on access to the Orc source repository.

## Operator flow

1. In Orc, create a Project with a display name and its canonical `owner/repository`. You do not need to find or enter an Amp project ID.
2. Open **Project → Settings** and copy the generated setup prompt.
3. Paste it into an already-authenticated agent thread opened in the Amp project you want to link. This becomes the dedicated trusted controller thread for the new connection; use a thread you will keep unarchived.
4. The prompt assumes no existing Orc installation. The agent loads Amp's built-in `building-plugins` guidance, downloads the immutable `orc-worker-v3.ts` artifact from the deployed Orc domain, verifies the prompt-pinned SHA-256, inspects it, and publishes only that file to the authenticated user's writable **Personal Plugins** repository. It preserves unrelated plugins. A project, workspace, or machine-only installation is insufficient because later workflow stages run in fresh Orbs.
5. The setup agent uses Amp's `reload_plugins` tool itself when that supported capability is available. Only when it is unavailable does it ask the operator to run **plugins: reload**. New threads load the published Personal Plugin automatically. If the account has no writable Personal Plugins scope, setup stops with that explicit prerequisite rather than pretending a reload can create the tool.
6. Once `orc_setup_project` is active, the agent calls it without placing the setup capability in a shell command or file. The first valid claim atomically binds the actual `AMP_PROJECT_ID` to this Project and connection; every retry and completion must match it. The tool claims the short-lived capability, verifies the controller source SHA-256, and writes only:
   - `.amp/plugins/orc-integration/index.ts`;
   - `.amp/runtime/orc-plugin.json` with mode `0600`;
   - the controller-generated `.amp/runtime/launch-webhook-url` with mode `0600`.
7. The newly installed project controller requires another reload. The agent again uses `reload_plugins` itself when available, with operator-run **plugins: reload** only as fallback. The second setup-tool call submits the webhook directly over HTTPS and queues verification.
8. Refresh Orc. A successful harmless check shows the connection as **verified**, links the actual webhook-owning controller thread reported by the handler, and separately links its fresh verification thread. A returned webhook URL or HTTP 202 alone does not prove handler ownership or success. If Amp reports a different owner than the setup thread, Orc fails verification instead of fabricating the binding.

The setup is therefore two-phase: Personal Worker bootstrap, then project pairing. It never assumes `orc_setup_project` already exists. Worker v3 contains only `orc_setup_project`, `workflow_verify_project_connection`, and `workflow_complete`; it adds Explanation completion and evidence verification while ordinary GitHub work continues to use Amp's native tools. Immutable Worker v2 remains available only for historical protocol-2 connections and workflow-v4 runs.

## Capability and secret boundary

- Project creation allocates a durable connection UUID and distinct directional secrets before webhook registration, removing the old circular dependency.
- The setup capability expires after 20 minutes and is bound on first claim to one connection, the actual `AMP_PROJECT_ID`, owner, and Amp thread. It can be retried only by that same claimant. Reissue revokes prior unconsumed setup capabilities.
- The copied prompt necessarily carries the narrow setup capability because the Plugin API provides no secret-input channel for a custom tool. It is not a reusable launch/callback secret. The agent is told not to repeat it; API responses never reflect it, responses are `no-store`, and application code does not log it.
- Worker bootstrap does not use the setup capability. The generated prompt prohibits putting it in shell commands, plugin repositories, files, or logs; it is supplied only to `orc_setup_project` after the verified Personal Plugin is active.
- The HTTPS claim response gives the setup tool—not a workflow prompt—the pinned controller and runtime material. The tool writes it only to gitignored, owner-readable runtime storage. Fresh workflow Orbs receive only per-stage capabilities, never directional controller secrets.
- Controller source is sent with and checked against a SHA-256 pinned into the setup row. Completion must attest that exact digest and an SSRF-guarded Amp webhook URL.
- Setup completion immediately queues the existing harmless fresh-Orb placement/native repository-read verification. Only a verified connection can launch workflows.

## Controller lifecycle and recovery

- Amp stores incoming webhook events and wakes an ordinarily idle controller Orb. Natural sleep is expected; do not add polling, keep-alive turns, or schedules.
- The thread that first registered a user/project/plugin/key remains the webhook owner. Loading the same key elsewhere returns the same URL but does not transfer ownership or resume an archived owner. Orc therefore records the thread ID only from the actual webhook handler context during verification, and requires it to equal the dedicated setup thread.
- Keep that controller thread unarchived. If it is archived, restore it. If the trigger was separately paused in Amp settings, resume it there as well. Orc never archives, restores, or resumes threads/triggers automatically.
- HTTP 404 means only that the endpoint is unavailable; Orc does not claim it proves archival. Open the recorded controller link, inspect/restore it if needed, then use **Verify in fresh Orb**. If the owner cannot be recovered, use **Generate new setup prompt** to create a new immutable connection version.
- Existing runs never move to a replacement connection. A still-active queued event can resume on its original owner. A run already failed from a definite unavailable endpoint remains immutable; recover/reverify the Project and start a new run. Ambiguous launches are never retried blindly.

## GitHub access invariant

Orc uses only the Git and GitHub authentication that the user already configured for the target Amp project/Orb. Setup never creates, copies, injects, repairs, or broadens `GH_TOKEN`, `GITHUB_TOKEN`, credential helpers, repository grants, or project secrets. Missing native access is a verification failure and explicit prerequisite blocker.

## Consent scope

The generated prompt authorizes only personal Orc worker use, this project controller installation/update, webhook pairing, and one harmless verification. It does not authorize code changes, workflow starts, branch/PR creation, GitHub comments, merges, credential changes, or unrelated plugin edits.
