import type { PluginAPI } from '@ampcode/plugin'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { createHash, randomUUID } from 'node:crypto'
import { chmodSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

export const description = 'Adds only Orc setup, verification, and stage-completion capabilities; ordinary GitHub work uses native Orb tools.'

type CapabilityInput = {
	capability_url: string
	capability_token: string
}

type LaunchContext = {
	schema_version: 1
	project_id: number
	amp_project_id: string
	connection_id: string
	stage_run_id: number
	workflow_run_id: number
	stage_key: string
	stage_name: string
	agent_mode: string
	attempt_number: number
	github_repository: string
	github_issue_number: number
	github_issue_url: string
	report_nonce: string
	expected_branch: string
	prior_github_branch?: string
	prior_pull_request_number?: number
	prior_pull_request_url?: string
	approved_pull_request_head_sha?: string
	merge_review_cycles: number
	github_branch?: string
	github_pull_request_number?: number
	github_pull_request_url?: string
	github_pull_request_head_sha?: string
	github_merge_commit_sha?: string
	github_report_url?: string
	github_report_comment_id?: number
	github_report_kind?: string
	allowed_outcomes: string[]
	thread_id: string
	completed: boolean
	outcome?: string
	is_active: boolean
}

type GitHubComment = {
	id: number
	body: string | null
	html_url: string
	issue_url?: string
	user?: { id?: number; login?: string }
}

type GitHubUser = { id: number; login?: string }

type GitHubPullRequest = {
	number: number
	html_url: string
	body: string | null
	state: string
	merged_at?: string | null
	merge_commit_sha?: string | null
	head?: { ref?: string; sha?: string; repo?: { full_name?: string } }
	base?: { repo?: { full_name?: string } }
}

type GhRunner = (args: string[]) => Promise<string>

const execFileAsync = promisify(execFile)
let ghRunner: GhRunner = async (args) => {
	try {
		const { stdout } = await execFileAsync('gh', args, {
			encoding: 'utf8',
			maxBuffer: 2 * 1024 * 1024,
			timeout: 30_000,
		})
		return stdout
	} catch {
		throw new Error('Native Orb GitHub access is unavailable. Configure repository access in Amp before starting Orc; Orc will not provision or copy credentials.')
	}
}

export function setGhRunnerForTests(runner?: GhRunner) {
	ghRunner = runner ?? (async () => {
		throw new Error('No test GitHub runner was configured.')
	})
}

export default function (amp: PluginAPI) {
	amp.registerTool({
		name: 'orc_setup_project',
		title: 'Set up an Orc project connection',
		description: 'Install and pair the versioned Orc controller for this exact Amp project using a short-lived setup capability.',
		inputSchema: {
			type: 'object',
			properties: {
				setup_url: { type: 'string', format: 'uri' },
				setup_id: { type: 'string', format: 'uuid' },
				setup_capability: { type: 'string', minLength: 32 },
			},
			required: ['setup_url', 'setup_id', 'setup_capability'],
			additionalProperties: false,
		},
		execute: async (input, ctx) => {
			const setupUrl = String(input.setup_url ?? '')
			const setupId = String(input.setup_id ?? '')
			const capability = String(input.setup_capability ?? '')
			const ampProjectId = actualAmpProjectId()
			if (!setupUrl.startsWith('https://') || !/^[0-9a-f-]{36}$/i.test(setupId) || capability.length < 32) {
				throw new Error('A valid short-lived Orc project setup capability is required.')
			}

			const claim = await projectSetupCall(setupUrl, capability, {
				schema_version: 1,
				action: 'claim',
				setup_id: setupId,
				thread_id: ctx.thread.id,
				amp_project_id: ampProjectId,
			})
			if (claim.disposition === 'already_completed') {
				return `Orc setup is already paired; verification status is ${String(claim.verification_status ?? 'pending')}.`
			}

			const source = String(claim.controller_source ?? '')
			const sourceHash = String(claim.controller_source_sha256 ?? '')
			const runtime = claim.runtime_config as Record<string, unknown> | undefined
			if (
				claim.amp_project_id !== ampProjectId
				|| source.length < 100
				|| !/^[0-9a-f]{64}$/.test(sourceHash)
				|| createHash('sha256').update(source).digest('hex') !== sourceHash
				|| !validRuntimeConfig(runtime, ampProjectId)
			) throw new Error('Orc returned controller material that does not match this Amp project or its pinned artifact hash.')

			const rootUri = amp.system.workspaceRoot
			if (!rootUri) throw new Error('Open the target Amp project workspace before running Orc setup.')
			const root = amp.helpers.filePathFromURI(rootUri)
			const pluginDirectory = join(root, '.amp', 'plugins', 'orc-integration')
			const runtimeDirectory = join(root, '.amp', 'runtime')
			const controllerPath = join(pluginDirectory, 'index.ts')
			const runtimePath = join(runtimeDirectory, 'orc-plugin.json')
			const webhookPath = join(runtimeDirectory, 'launch-webhook-url')
			const installed = readJson(runtimePath)
			const alreadyInstalled = installed?.controllerSourceSha256 === sourceHash
				&& installed?.connectionId === runtime!.connectionId
				&& installed?.ampProjectId === ampProjectId

			if (!alreadyInstalled) {
				mkdirSync(pluginDirectory, { recursive: true, mode: 0o700 })
				mkdirSync(runtimeDirectory, { recursive: true, mode: 0o700 })
				writePrivateAtomic(controllerPath, source)
				writePrivateAtomic(runtimePath, JSON.stringify({ ...runtime, controllerSourceSha256: sourceHash }, null, 2) + '\n')
				rmSync(webhookPath, { force: true })

				return 'Installed the pinned Orc project controller and owner-readable runtime configuration without changing unrelated plugins. Amp must load the new controller: run “plugins: reload” once in this same thread, then ask me to continue setup.'
			}

			const webhookUrl = await waitForPrivateFile(webhookPath)
			if (!webhookUrl.startsWith('https://')) {
				throw new Error('The Orc controller is installed but has not published its webhook. Run “plugins: reload” in this thread, then retry setup.')
			}
			const completed = await projectSetupCall(setupUrl, capability, {
				schema_version: 1,
				action: 'complete',
				setup_id: setupId,
				thread_id: ctx.thread.id,
				amp_project_id: ampProjectId,
				launch_webhook_url: webhookUrl,
				controller_source_sha256: sourceHash,
				controller_protocol_version: 2,
			})

			return `Orc paired this controller and queued harmless fresh-Orb/native repository verification. Current verification status: ${String(completed.verification_status ?? 'verifying')}. No workflow or code change was started.`
		},
	})

	amp.registerTool({
		name: 'workflow_verify_project_connection',
		title: 'Verify Orc project connection',
		description: 'Perform one harmless project-placement and native repository-access check for a pending Orc connection.',
		inputSchema: {
			type: 'object',
			properties: {
				verification_url: { type: 'string', format: 'uri' },
				verification_token: { type: 'string', minLength: 32 },
				github_repository: { type: 'string', pattern: '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' },
			},
			required: ['verification_url', 'verification_token', 'github_repository'],
			additionalProperties: false,
		},
		execute: async (input, ctx) => {
			const url = String(input.verification_url ?? '')
			const token = String(input.verification_token ?? '')
			const repository = String(input.github_repository ?? '')
			const ampProjectId = actualAmpProjectId()
			if (!url.startsWith('https://') || token.length < 32 || !/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository)) {
				throw new Error('A valid one-time Orc connection verification capability is required.')
			}
			let nativeAccess = false
			try {
				const result = await ghJson<{ nameWithOwner?: string }>(['repo', 'view', repository, '--json', 'nameWithOwner'])
				nativeAccess = result.nameWithOwner?.toLowerCase() === repository.toLowerCase()
			} catch {}
			const response = await fetch(url, {
				method: 'POST',
				headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
				body: JSON.stringify({
					schema_version: 1,
					event_id: randomUUID(),
					action: 'complete',
					thread_id: ctx.thread.id,
					amp_project_id: ampProjectId,
					github_repository: repository,
					native_github_access: nativeAccess,
				}),
				signal: AbortSignal.timeout(8_000),
			})
			const data = await response.json().catch(() => ({})) as Record<string, unknown>
			if (!response.ok || data.accepted !== true) {
				throw new Error(nativeAccess
					? 'Orc rejected this project-placement verification.'
					: 'Native Orb GitHub access to the canonical repository is unavailable; Orc will not provision credentials.')
			}

			return `Verified fresh-Orb placement and native read access for ${repository}.`
		},
	})

	amp.registerTool({
		name: 'workflow_complete',
		title: 'Complete Orc stage',
		description: 'Verify GitHub evidence created with normal native tools, bind it to this exact attempt, and complete the Orc stage.',
		inputSchema: {
			...capabilitySchema(),
			properties: {
				...capabilitySchema().properties,
				outcome: { type: 'string', enum: ['success', 'blocked', 'proof_complete', 'pass', 'fail', 'merged', 'requires_review'] },
				github_report_url: { type: 'string', format: 'uri', maxLength: 2048 },
				github_report_comment_id: { type: 'integer', minimum: 1 },
				github_branch: { type: 'string', minLength: 1, maxLength: 255 },
				github_pull_request_number: { type: 'integer', minimum: 1 },
				github_pull_request_url: { type: 'string', format: 'uri', maxLength: 2048 },
				github_pull_request_head_sha: { type: 'string', pattern: '^[a-fA-F0-9]{40}$' },
				github_merge_commit_sha: { type: 'string', pattern: '^[a-fA-F0-9]{40}$' },
			},
			required: [
				'capability_url',
				'capability_token',
				'outcome',
				'github_report_url',
				'github_report_comment_id',
			],
		},
		execute: async (input, ctx) => {
			const capability = capabilityInput(input)
			const context = await contextForCompletion(capability, ctx.thread.id)
			const outcome = String(input.outcome)
			if (context.completed && context.outcome === outcome) return `Orc already accepted ${outcome}.`
			if (!context.is_active) throw new Error('This Orc stage is no longer active.')
			if (!context.allowed_outcomes.includes(outcome)) {
				throw new Error(`Outcome ${outcome} is not permitted for ${context.stage_name}.`)
			}

			const reportKind = expectedReportKind(context, outcome)
			const reportUrl = String(input.github_report_url)
			const reportCommentId = Number(input.github_report_comment_id)
			const reportPullRequest = await verifyReportEvidence(context, reportKind, reportUrl, reportCommentId)
			let pullRequestHeadSha: string | undefined

			if (context.agent_mode === 'real_development' && outcome === 'success') {
				const branch = String(input.github_branch ?? '')
				const pullRequestNumber = Number(input.github_pull_request_number)
				const pullRequestUrl = String(input.github_pull_request_url ?? '')
				const pullRequest = await verifyPublication(context, branch, pullRequestNumber, pullRequestUrl)
				pullRequestHeadSha = pullRequest.head?.sha
				const publication = await capabilityCall(capability, ctx.thread.id, 'publication', {
					github_branch: branch,
					github_pull_request_number: pullRequestNumber,
					github_pull_request_url: pullRequestUrl,
					github_pull_request_head_sha: pullRequestHeadSha,
				})
				if (!publication.accepted) throw new Error(`Orc rejected publication: ${publication.reason ?? publication.disposition}`)
			}
			if (context.agent_mode === 'real_qa') {
				pullRequestHeadSha = reportPullRequest?.head?.sha
				if (!pullRequestHeadSha) throw new Error('The exact QA pull-request head could not be verified.')
			}
			if (context.agent_mode === 'real_merge') {
				if (!reportPullRequest) throw new Error('The exact Merge pull request could not be verified.')
				pullRequestHeadSha = reportPullRequest.head?.sha
				if (!pullRequestHeadSha) throw new Error('GitHub did not return the exact Merge pull-request head.')
				const suppliedHeadSha = String(input.github_pull_request_head_sha ?? '')
				if (outcome !== 'blocked' && (
					!/^[a-f0-9]{40}$/i.test(suppliedHeadSha)
					|| pullRequestHeadSha !== suppliedHeadSha
				)) {
					throw new Error('The supplied pull-request head is not the current head of the exact bound pull request.')
				}
				if (outcome === 'merged') {
					const mergeCommitSha = String(input.github_merge_commit_sha ?? '')
					if (
						!reportPullRequest.merged_at
						|| reportPullRequest.state !== 'closed'
						|| !/^[a-f0-9]{40}$/i.test(mergeCommitSha)
						|| reportPullRequest.merge_commit_sha !== mergeCommitSha
					) throw new Error('GitHub does not confirm the exact pull request and merge commit as merged.')
					const merge = await capabilityCall(capability, ctx.thread.id, 'merge', {
						github_pull_request_number: reportPullRequest.number,
						github_pull_request_url: reportPullRequest.html_url,
						github_pull_request_head_sha: pullRequestHeadSha,
						github_merge_commit_sha: mergeCommitSha,
					})
					if (!merge.accepted) throw new Error(`Orc rejected merge evidence: ${merge.reason ?? merge.disposition}`)
				} else if (outcome === 'requires_review') {
					if (
						reportPullRequest.state !== 'open'
						|| !context.approved_pull_request_head_sha
						|| pullRequestHeadSha === context.approved_pull_request_head_sha
						|| context.merge_review_cycles >= 1
					) throw new Error('This is not a permitted first material-conflict resolution on a new open pull-request head.')
				}
			}

			const report = await capabilityCall(capability, ctx.thread.id, 'report', {
				github_report_url: reportUrl,
				github_report_comment_id: reportCommentId,
				github_report_kind: reportKind,
				github_pull_request_head_sha: pullRequestHeadSha,
			})
			if (!report.accepted) throw new Error(`Orc rejected report: ${report.reason ?? report.disposition}`)

			const result = await capabilityCall(capability, ctx.thread.id, 'complete', {
				outcome,
				github_report_url: reportUrl,
			})
			if (!result.accepted) throw new Error(`Orc rejected completion: ${result.reason ?? result.disposition}`)

			return `Orc verified the supplied GitHub evidence and accepted ${outcome}; workflow is now at ${result.current_stage ?? 'the next stage'}.`
		},
	})
}

async function projectSetupCall(url: string, token: string, payload: Record<string, unknown>) {
	let response: Response
	try {
		response = await fetch(url, {
			method: 'POST',
			headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
			body: JSON.stringify(payload),
			signal: AbortSignal.timeout(10_000),
		})
	} catch {
		throw new Error('The Orc setup service could not be reached securely. Retry the same setup prompt; do not generate or copy credentials.')
	}
	const data = await response.json().catch(() => ({})) as Record<string, unknown>
	if (!response.ok || data.accepted !== true) {
		throw new Error(typeof data.message === 'string' ? data.message : 'Orc rejected this project setup request.')
	}

	return data
}

function validRuntimeConfig(config: Record<string, unknown> | undefined, ampProjectId: string) {
	return config !== undefined
		&& typeof config.connectionId === 'string'
		&& config.ampProjectId === ampProjectId
		&& config.controllerProtocolVersion === 2
		&& typeof config.launchSigningSecret === 'string'
		&& config.launchSigningSecret.length >= 32
		&& typeof config.callbackSigningSecret === 'string'
		&& config.callbackSigningSecret.length >= 32
		&& config.launchSigningSecret !== config.callbackSigningSecret
}

function readJson(path: string): Record<string, unknown> | null {
	try {
		return JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>
	} catch {
		return null
	}
}

function writePrivateAtomic(path: string, contents: string) {
	const temporary = `${path}.${randomUUID()}.tmp`
	writeFileSync(temporary, contents, { mode: 0o600 })
	renameSync(temporary, path)
	chmodSync(path, 0o600)
}

async function waitForPrivateFile(path: string): Promise<string> {
	for (let attempt = 0; attempt < 10; attempt++) {
		try {
			const value = readFileSync(path, 'utf8').trim()
			if (value) return value
		} catch {}
		await sleep(250)
	}

	return ''
}

async function verifyPublication(
	context: LaunchContext,
	branch: string,
	pullRequestNumber: number,
	pullRequestUrl: string,
): Promise<GitHubPullRequest> {
	if (branch !== context.expected_branch || !Number.isInteger(pullRequestNumber)) {
		throw new Error('The pull request branch or number does not match this Development attempt.')
	}
	const pullRequest = await ghJson<GitHubPullRequest>([
		'api', `/repos/${context.github_repository}/pulls/${pullRequestNumber}`,
	])
	if (
		pullRequest.number !== pullRequestNumber
		|| pullRequest.html_url !== pullRequestUrl
		|| pullRequest.state !== 'open'
		|| pullRequest.head?.ref !== branch
		|| pullRequest.head?.repo?.full_name?.toLowerCase() !== context.github_repository.toLowerCase()
		|| pullRequest.base?.repo?.full_name?.toLowerCase() !== context.github_repository.toLowerCase()
		|| !pullRequest.body?.includes(developmentMarker(context))
	) {
		throw new Error('The pull request is not the open, marker-bound publication for this Development attempt.')
	}

	return pullRequest
}

async function verifyReportEvidence(
	context: LaunchContext,
	reportKind: string,
	reportUrl: string,
	commentId: number,
) {
	if (!Number.isInteger(commentId)) throw new Error('A valid GitHub report comment ID is required.')
	const [actor, comment] = await Promise.all([
		ghJson<GitHubUser>(['api', '/user']),
		ghJson<GitHubComment>(['api', `/repos/${context.github_repository}/issues/comments/${commentId}`]),
	])
	const pullRequestReport = context.agent_mode === 'real_qa' || context.agent_mode === 'real_merge'
	const targetNumber = pullRequestReport
		? context.prior_pull_request_number
		: context.github_issue_number
	if (!targetNumber) throw new Error('This stage has no bound GitHub report target.')
	const expectedIssueApiPath = `/repos/${context.github_repository}/issues/${targetNumber}`.toLowerCase()
	let issueApiPath = ''
	let report: URL
	try {
		issueApiPath = new URL(String(comment.issue_url)).pathname.toLowerCase()
		report = new URL(reportUrl)
	} catch {
		throw new Error('The GitHub report returned invalid evidence URLs.')
	}
	const expectedWebPath = `/${context.github_repository}/${pullRequestReport ? 'pull' : 'issues'}/${targetNumber}`.toLowerCase()
	if (
		comment.id !== commentId
		|| comment.html_url !== reportUrl
		|| comment.user?.id !== actor.id
		|| issueApiPath !== expectedIssueApiPath
		|| report.hostname.toLowerCase() !== 'github.com'
		|| report.pathname.toLowerCase() !== expectedWebPath
		|| report.hash !== `#issuecomment-${commentId}`
		|| !comment.body?.includes(reportMarker(context, reportKind))
	) {
		throw new Error('The GitHub report is not the native-user-authored, marker-bound comment for this exact stage target and outcome.')
	}

	if (!pullRequestReport) return undefined

	const pullRequest = await ghJson<GitHubPullRequest>([
		'api', `/repos/${context.github_repository}/pulls/${targetNumber}`,
	])
	if (
		pullRequest.number !== targetNumber
		|| pullRequest.html_url !== context.prior_pull_request_url
		|| pullRequest.head?.repo?.full_name?.toLowerCase() !== context.github_repository.toLowerCase()
		|| pullRequest.base?.repo?.full_name?.toLowerCase() !== context.github_repository.toLowerCase()
	) throw new Error('The report target is not the exact bound pull request in the canonical repository.')

	return pullRequest
}

function expectedReportKind(context: LaunchContext, outcome: string) {
	const expected = context.agent_mode === 'real_development'
		? ['success', 'blocked']
		: context.agent_mode === 'real_qa'
			? ['pass', 'fail', 'blocked']
			: context.agent_mode === 'real_merge'
				? ['merged', 'requires_review', 'blocked']
				: ['proof_complete']
	if (!expected.includes(outcome)) throw new Error(`Outcome ${outcome} is invalid for ${context.agent_mode}.`)

	return outcome === 'proof_complete' ? 'proof' : outcome
}

async function contextForCompletion(capability: CapabilityInput, threadId: string) {
	const context = await capabilityCall(capability, threadId, 'context') as LaunchContext
	if (context.completed) return context
	if (!context.is_active) throw new Error('This Orc stage is no longer active.')
	return context
}

async function capabilityCall(
	capability: CapabilityInput,
	threadId: string,
	action: 'context' | 'report' | 'publication' | 'merge' | 'complete',
	extra: Record<string, unknown> = {},
) {
	const eventId = randomUUID()
	const payload = {
		schema_version: 1,
		event_id: eventId,
		action,
		occurred_at: new Date().toISOString(),
		thread_id: threadId,
		amp_project_id: actualAmpProjectId(),
		...extra,
	}
	let lastError: unknown
	for (const delay of [0, 400, 1200]) {
		if (delay) await sleep(delay)
		try {
			const response = await fetch(capability.capability_url, {
				method: 'POST',
				headers: {
					accept: 'application/json',
					'content-type': 'application/json',
					authorization: `Bearer ${capability.capability_token}`,
				},
				body: JSON.stringify(payload),
				signal: AbortSignal.timeout(8_000),
			})
			const data = await response.json().catch(() => ({})) as Record<string, any>
			if (response.ok) return data
			if (response.status < 500) throw new Error(data.message ?? `Orc returned ${response.status}.`)
			throw new Error(`Orc returned ${response.status}.`)
		} catch (error) {
			lastError = error
		}
	}
	throw lastError instanceof Error ? lastError : new Error('Orc stage capability request failed.')
}

function actualAmpProjectId() {
	const projectId = process.env.AMP_PROJECT_ID
	if (!projectId) throw new Error('Amp did not expose this worker Orb project identity.')

	return projectId
}

async function ghJson<T>(args: string[]): Promise<T> {
	const output = await ghRunner(args)
	try {
		return JSON.parse(output) as T
	} catch {
		throw new Error('Native GitHub command returned malformed JSON.')
	}
}

function capabilitySchema() {
	return {
		type: 'object',
		properties: {
			capability_url: { type: 'string', format: 'uri' },
			capability_token: { type: 'string', minLength: 32 },
		},
		required: ['capability_url', 'capability_token'],
		additionalProperties: false,
	} as const
}

function capabilityInput(input: Record<string, unknown>): CapabilityInput {
	const capability_url = String(input.capability_url ?? '')
	const capability_token = String(input.capability_token ?? '')
	if (!capability_url.startsWith('https://') || capability_token.length < 32) {
		throw new Error('A valid stage-scoped Orc capability is required.')
	}
	return { capability_url, capability_token }
}

function reportMarker(context: LaunchContext, reportKind: string) {
	if (!/^[a-f0-9]{64}$/.test(context.report_nonce)) throw new Error('Orc report nonce is invalid.')
	return `<!-- orc-report:${context.report_nonce}:${reportKind} -->`
}

function developmentMarker(context: LaunchContext) {
	return `<!-- orc-development:${context.report_nonce} -->`
}

async function sleep(milliseconds: number) {
	await new Promise((resolve) => setTimeout(resolve, milliseconds))
}
