import { afterEach, describe, expect, test } from 'bun:test'
import { createHmac } from 'node:crypto'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import controller from '../../.amp/plugins/orc-integration/index'

const originalFetch = globalThis.fetch

afterEach(() => {
	globalThis.fetch = originalFetch
})

function launch(overrides: Record<string, unknown> = {}) {
	return {
		schema_version: 1,
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

async function controllerHarness(testName: string) {
	const root = `/tmp/orc-controller-${testName}`
	rmSync(root, { recursive: true, force: true })
	mkdirSync(`${root}/.amp/runtime`, { recursive: true })
	writeFileSync(`${root}/.amp/runtime/orc-plugin.json`, JSON.stringify({
		callbackUrl: 'https://orc.test/api/integrations/amp',
		launchSigningSecret: 'launch-secret',
		callbackSigningSecret: 'callback-secret',
	}))

	let handler: ((event: any, ctx: any) => Promise<void>) | undefined
	let webhookKey: string | undefined
	let createThreadCalls = 0
	let cancelled = 0
	let prompted = 0
	const thread = {
		id: 'T-00000000-0000-0000-0000-000000000041',
		cancel: async () => { cancelled++ },
		appendUserMessage: async () => { prompted++ },
		messages: async () => [],
		waitForResponse: async () => undefined,
	}
	controller({
		system: { workspaceRoot: `file://${root}` },
		helpers: { filePathFromURI: () => root },
		createAgent: (config: Record<string, any>) => ({
			definition: config,
			createThread: async () => { createThreadCalls++; return thread },
		}),
		registerAgentMode: () => undefined,
		on: () => undefined,
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
	}
}

describe('Orc controller agent configuration', () => {
	test('extends the normal medium agent and adds workflow tools without replacing defaults', async () => {
		const root = '/tmp/orc-controller-test-configured'
		rmSync(root, { recursive: true, force: true })
		mkdirSync(`${root}/.amp/runtime`, { recursive: true })
		writeFileSync(`${root}/.amp/runtime/orc-plugin.json`, JSON.stringify({
			callbackUrl: 'https://orc.test/api/integrations/amp',
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

		expect(agentConfigs).toHaveLength(2)
		expect(modes.map((mode) => mode.key)).toEqual(['orc-proof-agent', 'orc-development-agent'])
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
		expect(harness.webhookKey()).toBe('orc-stage-launch-v2')
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
