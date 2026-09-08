import { afterEach, describe, expect, test } from 'bun:test'
import { createHmac } from 'node:crypto'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import controller from '../../.amp/plugins/orc-integration/index'

const originalFetch = globalThis.fetch
const originalAmpProjectId = process.env.AMP_PROJECT_ID

afterEach(() => {
	globalThis.fetch = originalFetch
	process.env.AMP_PROJECT_ID = originalAmpProjectId
})

function launch(overrides: Record<string, unknown> = {}) {
	return {
		schema_version: 1,
		project_id: 1,
		connection_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		amp_project_id: 'amp-project-test',
		controller_key: 'orc-stage-launch-v11-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		callback_url: 'https://orc.test/api/integrations/amp/connections/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		event_id: '11111111-1111-4111-8111-111111111111',
		idempotency_key: '22222222-2222-4222-8222-222222222222',
		stage_run_id: 41,
		workflow_run_id: 9,
		stage_key: 'development',
		stage_name: 'Real Development',
		agent_mode: 'real_development',
		attempt_number: 1,
		github_repository: 'acme/widgets',
		github_issue_number: 42,
		github_issue_url: 'https://github.com/acme/widgets/issues/42',
		report_nonce: 'a'.repeat(64),
		stage_capability_url: 'https://orc.test/api/integrations/amp/stage-capability',
		stage_capability_token: 'stage-capability-token-that-is-long-enough',
		expected_branch: 'orc/stage-41-attempt-1',
		allowed_outcomes: ['success', 'blocked'],
		...overrides,
	}
}

async function controllerHarness(testName: string, holdResponses = false) {
	process.env.AMP_PROJECT_ID = 'amp-project-test'
	const root = `/tmp/orc-controller-${testName}`
	rmSync(root, { recursive: true, force: true })
	mkdirSync(`${root}/.amp/runtime`, { recursive: true })
	writeFileSync(`${root}/.amp/runtime/orc-plugin.json`, JSON.stringify({
		connectionId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		ampProjectId: 'amp-project-test',
		launchSigningSecret: 'launch-secret',
		callbackSigningSecret: 'callback-secret',
	}))

	let handler: ((event: any, ctx: any) => Promise<void>) | undefined
	let webhookKey: string | undefined
	let createThreadCalls = 0
	let createThreadError: Error | undefined
	let createThreadOptions: Record<string, unknown> | undefined
	let cancelled = 0
	let prompted = 0
	let monitorWaiting = false
	let monitorWasWaitingWhenPrompted = false
	let agentEndHandler: ((event: Record<string, any>) => Promise<unknown>) | undefined
	const thread = {
		id: 'T-00000000-0000-0000-0000-000000000041',
		cancel: async () => { cancelled++ },
		appendUserMessage: async () => {
			monitorWasWaitingWhenPrompted = monitorWaiting
			prompted++
		},
		messages: async () => [],
		waitForResponse: async () => {
			monitorWaiting = true
			return holdResponses ? await new Promise<never>(() => undefined) : undefined
		},
	}
	controller({
		system: { workspaceRoot: `file://${root}` },
		helpers: { filePathFromURI: () => root },
		createAgent: (config: Record<string, any>) => ({
			definition: config,
			createThread: async (options: Record<string, unknown>) => {
				createThreadCalls++
				createThreadOptions = options
				if (createThreadError) throw createThreadError
				return thread
			},
		}),
		registerAgentMode: () => undefined,
		on: (event: string, callback: typeof agentEndHandler) => {
			if (event === 'agent.end') agentEndHandler = callback
		},
		createWebhook: async (config: { key: string; handler: typeof handler }) => {
			webhookKey = config.key
			handler = config.handler
			return { url: 'https://amp.test/durable-webhook' }
		},
		threads: { get: () => thread },
		logger: { log: () => undefined },
	} as never)
	await new Promise((resolve) => setTimeout(resolve, 0))

	return {
		root,
		webhookKey: () => webhookKey,
		invoke: async (payload: Record<string, unknown>) => {
			if (!handler) throw new Error('Controller did not register its webhook.')
			const body = JSON.stringify(payload)
			const timestamp = Math.floor(Date.now() / 1000).toString()
			const signature = `sha256=${createHmac('sha256', 'launch-secret')
				.update(`${timestamp}.${payload.event_id}.${body}`)
				.digest('hex')}`
			await handler({
				body: new TextEncoder().encode(body),
				headers: {
					'idempotency-key': String(payload.idempotency_key),
					'x-orc-event-id': String(payload.event_id),
					'x-orc-timestamp': timestamp,
					'x-orc-signature': signature,
				},
			}, {
				thread: { id: 'T-controller' },
				signal: new AbortController().signal,
			})
		},
		counts: () => ({ createThreadCalls, cancelled, prompted }),
		createThreadOptions: () => createThreadOptions,
		failCreateThread: (error: Error) => { createThreadError = error },
		monitorWasWaitingWhenPrompted: () => monitorWasWaitingWhenPrompted,
		invokeAgentEnd: async (status: string) => {
			if (!agentEndHandler) throw new Error('Controller did not register agent.end.')
			return agentEndHandler({ thread: { id: thread.id }, status })
		},
	}
}

describe('Orc controller agent configuration', () => {
	test('extends the normal medium agent and adds workflow tools without replacing defaults', async () => {
		process.env.AMP_PROJECT_ID = 'amp-project-test'
		const root = '/tmp/orc-controller-test-configured'
		rmSync(root, { recursive: true, force: true })
		mkdirSync(`${root}/.amp/runtime`, { recursive: true })
		writeFileSync(`${root}/.amp/runtime/orc-plugin.json`, JSON.stringify({
			connectionId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			ampProjectId: 'amp-project-test',
			launchSigningSecret: 'launch-secret',
			callbackSigningSecret: 'callback-secret',
		}))
		const agentConfigs: Record<string, any>[] = []
		const modes: Record<string, any>[] = []
		controller({
			system: { workspaceRoot: `file://${root}` },
			helpers: { filePathFromURI: () => root },
			createAgent: (config: Record<string, any>) => {
				agentConfigs.push(config)
				return { definition: config, createThread: async () => ({ id: 'unused' }) }
			},
			registerAgentMode: (mode: Record<string, any>) => modes.push(mode),
			on: () => undefined,
			createWebhook: async () => { throw new Error('Managed Orb only') },
			logger: { log: () => undefined },
		} as never)
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(agentConfigs).toHaveLength(4)
		expect(modes.map((mode) => mode.key)).toEqual(['orc-proof-agent', 'orc-development-agent', 'orc-qa-agent'])
		for (const config of agentConfigs) {
			expect(config.extends).toBe('medium')
			expect(config.tools.include).toBeUndefined()
			expect(config.tools.exclude).toBeUndefined()
			expect(Array.isArray(config.tools.add)).toBeTrue()
		}
		expect(agentConfigs[0].tools.add).toEqual([
			'workflow_read_issue',
			'workflow_post_test_comment',
			'workflow_complete',
		])
		expect(agentConfigs[1].tools.add).toContain('workflow_record_publication')
		expect(agentConfigs[1].model).toBe('openai/gpt-5.6-sol')
		expect(agentConfigs[1].instructions).toContain('origin may be an Amp-hosted project remote')
		expect(agentConfigs[2].tools.add).toEqual([
			'workflow_read_qa_context',
			'workflow_post_qa_report',
			'workflow_complete',
		])
		expect(agentConfigs[2].model).toBe('openai/gpt-5.6-sol')
		expect(agentConfigs[2].instructions).toContain('Do not change implementation files')
		expect(agentConfigs[3].tools.add).toEqual(['workflow_verify_project_connection'])
		rmSync(root, { recursive: true, force: true })
	})

	test('does not register a controller, lifecycle hook, or webhook in an unconfigured worker Orb', () => {
		const calls: string[] = []
		controller({
			system: { workspaceRoot: 'file:///tmp/orc-controller-test-unconfigured' },
			helpers: { filePathFromURI: () => '/tmp/orc-controller-test-unconfigured' },
			createAgent: () => { calls.push('agent'); throw new Error('not expected') },
			registerAgentMode: () => calls.push('mode'),
			on: () => calls.push('hook'),
			createWebhook: async () => { calls.push('webhook'); throw new Error('not expected') },
		} as never)

		expect(calls).toEqual([])
	})

	test('marks a claimed launch without a durable thread ambiguous instead of creating a duplicate Orb', async () => {
		const harness = await controllerHarness('claimed-without-thread')
		expect(harness.webhookKey()).toBe('orc-stage-launch-v11-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
		const callbackTypes: string[] = []
		globalThis.fetch = (async (_input, init) => {
			const payload = JSON.parse(String(init?.body))
			callbackTypes.push(payload.type)
			if (payload.type === 'launch.claim') {
				return Response.json({ accepted: true, launch: false, is_active: true })
			}
			return Response.json({ accepted: true, disposition: 'ambiguous' })
		}) as typeof fetch

		await harness.invoke(launch())

		expect(callbackTypes).toEqual(['launch.claim', 'launch.ambiguous'])
		expect(harness.counts()).toEqual({ createThreadCalls: 0, cancelled: 0, prompted: 0 })
		rmSync(harness.root, { recursive: true, force: true })
	})

	test('reports a claimed connection verification when fresh thread creation fails', async () => {
		const harness = await controllerHarness('verification-thread-failed')
		harness.failCreateThread(new Error('provider unavailable'))
		const actions: string[] = []
		globalThis.fetch = (async (_input, init) => {
			const payload = JSON.parse(String(init?.body))
			actions.push(payload.action)
			if (payload.action === 'claim') return Response.json({ accepted: true, launch: true })
			if (payload.action === 'failed') return Response.json({ accepted: true, disposition: 'failed' })
			throw new Error(`Unexpected verification callback: ${JSON.stringify(payload)}`)
		}) as typeof fetch

		await expect(harness.invoke({
			schema_version: 1,
			command: 'verify_connection',
			event_id: '55555555-5555-4555-8555-555555555555',
			idempotency_key: '55555555-5555-4555-8555-555555555555',
			connection_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			expected_amp_project_id: 'amp-project-test',
			github_repository: 'acme/widgets',
			verification_url: 'https://orc.test/api/integrations/amp/connection-verification',
			verification_token: 'verification-capability-that-is-long-enough',
		})).rejects.toThrow('provider unavailable')

		expect(actions).toEqual(['claim', 'failed'])
		expect(harness.counts()).toEqual({ createThreadCalls: 1, cancelled: 0, prompted: 0 })
		expect(harness.createThreadOptions()?.parentThreadID).toBeUndefined()
		expect(harness.createThreadOptions()?.executor).toBe('orb')
		rmSync(harness.root, { recursive: true, force: true })
	})

	test('agent end gives one corrective turn even while the transcript monitor is waiting', async () => {
		const harness = await controllerHarness('agent-end-monitor-race', true)
		globalThis.fetch = (async (_input, init) => {
			const payload = JSON.parse(String(init?.body))
			if (payload.type === 'launch.claim') {
				return Response.json({
					accepted: true,
					launch: false,
					is_active: true,
					thread_id: 'T-00000000-0000-0000-0000-000000000041',
				})
			}
			if (payload.type === 'launch.acknowledged') return Response.json({ accepted: true })
			if (payload.action === 'context') {
				return Response.json({
					...launch(),
					thread_id: 'T-00000000-0000-0000-0000-000000000041',
					completed: false,
					is_active: true,
				})
			}
			throw new Error(`Unexpected callback: ${JSON.stringify(payload)}`)
		}) as typeof fetch

		await harness.invoke(launch())
		const result = await harness.invokeAgentEnd('done') as Record<string, unknown>

		expect(result.action).toBe('continue')
		expect(result.userMessage).toContain('Safety check')
		expect(harness.counts()).toEqual({ createThreadCalls: 0, cancelled: 0, prompted: 1 })
		expect(harness.monitorWasWaitingWhenPrompted()).toBeTrue()
		rmSync(harness.root, { recursive: true, force: true })
	})

	test('cancels a recovered thread without prompting when Laravel rejects acknowledgement', async () => {
		const harness = await controllerHarness('rejected-acknowledgement')
		const callbackTypes: string[] = []
		globalThis.fetch = (async (_input, init) => {
			const payload = JSON.parse(String(init?.body))
			callbackTypes.push(payload.type)
			if (payload.type === 'launch.claim') {
				return Response.json({
					accepted: true,
					launch: false,
					is_active: true,
					thread_id: 'T-00000000-0000-0000-0000-000000000041',
				})
			}
			return Response.json({ accepted: false, disposition: 'rejected' }, { status: 202 })
		}) as typeof fetch

		await harness.invoke(launch({
			event_id: '33333333-3333-4333-8333-333333333333',
			idempotency_key: '44444444-4444-4444-8444-444444444444',
		}))

		expect(callbackTypes).toEqual(['launch.claim', 'launch.acknowledged'])
		expect(harness.counts()).toEqual({ createThreadCalls: 0, cancelled: 1, prompted: 0 })
		rmSync(harness.root, { recursive: true, force: true })
	})
})
