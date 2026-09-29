/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * hermiq-ai-tooling, live: a hermiq agent calls learniq's curated MCP tools.
 *
 * Covers:
 *   @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-an-agent-proposed-grade-stays-invisible-until-a-teacher-publishes-it
 *   @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-the-renewal-sweep-works-on-minimised-data-alone
 *   @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-the-adr-023-matrix-gates-the-tool-before-any-domain-logic
 *
 * OPT-IN: this suite runs a real LLM through hermiq (it spends the instance's
 * model quota) and creates users, so it only runs with
 * `LEARNIQ_E2E_HERMIQ_LIVE=1` against an instance whose hermiq has a chat
 * provider configured. It asserts on what landed in the register, never on
 * the model's prose: the model is told exactly which tool to call with which
 * arguments, and the check is the object the tool wrote (or did not write).
 *
 * Everything it creates is named `lq-r5-e2e-*` and removed in afterAll:
 * users, agents, conversations, learniq objects, and the temporary `mcp.*`
 * grant for `instructors` in the action matrix, which is put back exactly.
 * One exception: the attendance record the agent writes cannot be deleted,
 * because AttendanceRecord is an archival schema; it expires through
 * OpenRegister's retention task.
 */
import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { baseUrl } from '../base-url.ts'

const LIVE = process.env.LEARNIQ_E2E_HERMIQ_LIVE === '1'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'
const OR = '/index.php/apps/openregister/api/objects/learniq'
const HERMIQ = '/index.php/apps/hermiq/api'
const MCP_ACTIONS = [
	'mcp.enrol-learner',
	'mcp.record-attendance',
	'mcp.grade-submission',
]
const CHAT_TIMEOUT = 300_000

interface Created {
	schema: string
	id: string
}

const runId = Date.now().toString(36)
const tenant = randomUUID()
const password = `Lq-r5-e2e-${runId}-pw!`
const users = {
	teacher: `lq-r5-e2e-teacher-${runId}`,
	coordinator: `lq-r5-e2e-coord-${runId}`,
	pupil: `lq-r5-e2e-pupil-${runId}`,
	pupil2: `lq-r5-e2e-pupil2-${runId}`,
}

const created: Created[] = []
const agents: string[] = []
const conversations: Array<{ ctx: APIRequestContext; id: string }> = []
const ids: Record<string, string> = {}
let admin: APIRequestContext
let teacher: APIRequestContext
let coordinator: APIRequestContext
let pupil: APIRequestContext
let savedMatrix: Record<string, string[]> | null = null

/**
 * A request context signed in as one user.
 *
 * @param user The uid.
 * @param pass The password.
 * @return The context.
 */
async function as(user: string, pass: string): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: baseUrl(),
		httpCredentials: { username: user, password: pass, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
}

/**
 * The JSON body of a response, failing with the body text when it is not OK.
 *
 * @param resp The response.
 * @param what What was asked, for the failure message.
 * @return The parsed body.
 */
async function okJson(resp: APIResponse, what: string): Promise<any> {
	const text = await resp.text()
	expect(resp.ok(), `${what}: HTTP ${resp.status()} ${text.slice(0, 400)}`).toBe(
		true,
	)
	return text === '' ? {} : JSON.parse(text)
}

/**
 * Create one learniq object and remember it for cleanup.
 *
 * @param ctx    Who creates it.
 * @param schema The schema slug.
 * @param body   The object.
 * @return The new uuid.
 */
async function createObject(
	ctx: APIRequestContext,
	schema: string,
	body: object,
): Promise<string> {
	const json = await okJson(
		await ctx.post(`${OR}/${schema}`, { data: { tenant_id: tenant, ...body } }),
		`create ${schema}`,
	)
	const id = json.id ?? json['@self']?.id
	created.push({ schema, id })
	return id
}

/**
 * learniq objects of a schema matching filters, read as admin.
 *
 * @param schema  The schema slug.
 * @param filters Equality filters.
 * @return The rows.
 */
async function find(
	schema: string,
	filters: Record<string, string>,
): Promise<any[]> {
	const query = new URLSearchParams({ ...filters, _limit: '50' })
	const json = await okJson(
		await admin.get(`${OR}/${schema}?${query}`),
		`find ${schema}`,
	)
	return json.results ?? []
}

/**
 * Send one chat message to an agent and return hermiq's answer.
 *
 * @param ctx     Who chats.
 * @param agentId The agent uuid.
 * @param message The message.
 * @return hermiq's answer (message, toolCalls, conversation).
 */
async function chat(
	ctx: APIRequestContext,
	agentId: string,
	message: string,
): Promise<any> {
	const resp = await ctx.post(`${HERMIQ}/chat/send`, {
		data: {
			agentUuid: agentId,
			message,
			includeObjects: false,
			includeFiles: false,
		},
		timeout: CHAT_TIMEOUT,
	})
	const json = await okJson(resp, 'chat')
	if (typeof json.conversation === 'string') {
		conversations.push({ ctx, id: json.conversation })
	}

	test.info().annotations.push({
		type: 'hermiq',
		description: JSON.stringify({
			message: json.message,
			toolCalls: json.toolCalls,
		}).slice(0, 1500),
	})
	return json
}

/**
 * Create a private agent that only the invited users may chat with.
 *
 * @param name    The agent name suffix.
 * @param tools   The granted tools.
 * @param invited The users who may use it.
 * @return The agent uuid.
 */
async function createAgent(
	name: string,
	tools: string[],
	invited: string[],
): Promise<string> {
	const json = await okJson(
		await admin.post(`${HERMIQ}/agents`, {
			data: {
				name: `lq-r5-e2e-${name}-${runId}`,
				description: 'Throwaway agent for the hermiq-ai-tooling live suite.',
				prompt: 'You operate learniq tools. When asked to call a tool, call exactly that tool with exactly the given arguments, then report the tool result as JSON. Do not call other tools.',
				tools,
				isPrivate: true,
				invitedUsers: invited,
			},
		}),
		'create agent',
	)
	const id = json.id ?? json.uuid ?? json['@self']?.id
	agents.push(id)
	return id
}

test.describe('hermiq-ai-tooling: an agent calls learniq tools (live, opt-in)', () => {
	test.skip(
		!LIVE,
		'Runs a real LLM through hermiq and creates users; set LEARNIQ_E2E_HERMIQ_LIVE=1 to run it.',
	)
	test.setTimeout(CHAT_TIMEOUT + 60_000)

	test.beforeAll(async () => {
		test.setTimeout(180_000)
		admin = await as(ADMIN_USER, ADMIN_PASS)
		const groups: Record<string, string[]> = {
			teacher: ['instructors', 'hr'],
			coordinator: ['coordinators'],
			pupil: ['learners'],
			pupil2: ['learners'],
		}
		for (const [role, uid] of Object.entries(users)) {
			const form: Record<string, string> = { userid: uid, password }
			const body = new URLSearchParams(form)
			for (const group of groups[role]) {
				body.append('groups[]', group)
			}

			await okJson(
				await admin.post('/ocs/v2.php/cloud/users?format=json', {
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					data: body.toString(),
				}),
				`create user ${role}`,
			)
		}

		teacher = await as(users.teacher, password)
		coordinator = await as(users.coordinator, password)
		pupil = await as(users.pupil, password)

		// Temporarily let instructors use the three write tools; restored in afterAll.
		savedMatrix = (
			await okJson(
				await admin.get('/index.php/apps/learniq/api/admin/action-matrix'),
				'read matrix',
			)
		).matrix
		const widened = structuredClone(savedMatrix)
		for (const action of MCP_ACTIONS) {
			widened[action] = [
				...new Set([...(widened[action] ?? ['admin']), 'instructors']),
			]
		}

		await okJson(
			await admin.put('/index.php/apps/learniq/api/admin/action-matrix', {
				data: { matrix: widened },
			}),
			'widen matrix',
		)

		// No LearnerProfile: that schema is archival (no delete), so each run would
		// leave one behind. The credential names the learner by learnerUserId, which
		// is what the expiring-credentials projection reads first.
		ids.profile = randomUUID()
		ids.scale = await createObject(teacher, 'grade-scale', {
			name: `lq-r5-e2e scale ${runId}`,
			kind: 'numeric',
			min: 1,
			max: 10,
			passThreshold: 5.5,
			lifecycle: 'active',
		})
		ids.component = `lq-r5-e2e-comp-${runId}`
		ids.plan = await createObject(teacher, 'curriculum-plan', {
			name: `lq-r5-e2e plan ${runId}`,
			kind: 'generic',
			formula: 'weighted-average',
			gradeScaleId: ids.scale,
			components: [
				{
					componentId: ids.component,
					label: 'Opdracht',
					weight: 1,
					period: 1,
					kind: 'assignment',
				},
			],
		})
		ids.expiringCourse = await createObject(teacher, 'course', {
			code: `LQR5-A-${runId}`,
			name: `lq-r5-e2e BHV ${runId}`,
			level: 'mbo',
			language: 'nl',
			lifecycle: 'published',
			renewalCourseSlug: `lq-r5-e2e-renewal-${runId}`,
		})
		ids.renewalCourse = await createObject(teacher, 'course', {
			code: `LQR5-R-${runId}`,
			name: `lq-r5-e2e BHV herhaling ${runId}`,
			level: 'mbo',
			language: 'nl',
			lifecycle: 'published',
		})
		ids.gradedCourse = await createObject(teacher, 'course', {
			code: `LQR5-G-${runId}`,
			name: `lq-r5-e2e Nederlands ${runId}`,
			level: 'mbo',
			language: 'nl',
			lifecycle: 'published',
			curriculumPlanId: ids.plan,
		})
		const now = Date.now()
		ids.credential = await createObject(teacher, 'credential', {
			learnerId: ids.profile,
			learnerUserId: users.pupil,
			courseId: ids.expiringCourse,
			kind: 'certificate',
			issuedAt: new Date(now - 300 * 86400_000).toISOString(),
			expiresAt: new Date(now + 20 * 86400_000).toISOString(),
			issuerDid: 'did:web:lq-r5-e2e.invalid',
			signature: 'lq-r5-e2e-not-a-signature',
			openbadges3Payload: { note: 'lq-r5-e2e throwaway' },
			lifecycle: 'issued',
		})
		ids.cohort = await createObject(teacher, 'cohort', {
			name: `lq-r5-e2e klas ${runId}`,
			period: '2026-2027',
			academicYear: '2026-2027',
			lifecycle: 'active',
			learnerIds: [users.pupil, users.pupil2],
		})
		ids.session = await createObject(teacher, 'session', {
			cohortId: ids.cohort,
			title: `lq-r5-e2e les ${runId}`,
			startsAt: new Date(now).toISOString(),
			endsAt: new Date(now + 3600_000).toISOString(),
			lifecycle: 'scheduled',
		})
		ids.assignment = await createObject(teacher, 'assignment', {
			title: `lq-r5-e2e opdracht ${runId}`,
			maxPoints: 10,
			courseId: ids.gradedCourse,
			curriculumPlanComponentId: ids.component,
			lifecycle: 'published',
		})
		ids.submission1 = await createObject(teacher, 'submission', {
			assignmentId: ids.assignment,
			learnerIds: [users.pupil],
		})
		ids.submission2 = await createObject(teacher, 'submission', {
			assignmentId: ids.assignment,
			learnerIds: [users.pupil2],
		})

		ids.teacherAgent = await createAgent(
			'teacher-agent',
			[
				'learniq_listExpiringCredentials',
				'learniq_enrolLearner',
				'learniq_recordAttendance',
				'learniq_gradeSubmission',
			],
			[users.teacher],
		)
		ids.coordinatorAgent = await createAgent(
			'coord-agent',
			['learniq_enrolLearner'],
			[users.coordinator],
		)
	})

	test.afterAll(async () => {
		test.setTimeout(180_000)
		if (admin === undefined) {
			return
		}

		// Objects the tools wrote, found by what they point at.
		for (const [schema, filters] of [
			['enrolment', { courseId: ids.renewalCourse }],
			['attendance-record', { sessionId: ids.session }],
			['grade-entry', { submissionId: ids.submission1 }],
			['grade-entry', { submissionId: ids.submission2 }],
		] as Array<[string, Record<string, string>]>) {
			if (Object.values(filters)[0] === undefined) {
				continue
			}

			for (const row of await find(schema, filters).catch(() => [])) {
				created.push({ schema, id: row.id ?? row['@self']?.id })
			}
		}

		for (const { schema, id } of created.reverse()) {
			await admin.delete(`${OR}/${schema}/${id}`).catch(() => undefined)
		}

		for (const { ctx, id } of conversations) {
			await ctx.delete(`${HERMIQ}/sessions/${id}`).catch(() => undefined)
			await ctx
				.delete(`${HERMIQ}/sessions/${id}/permanent`)
				.catch(() => undefined)
		}

		for (const agent of agents) {
			await admin.delete(`${HERMIQ}/agents/${agent}`).catch(() => undefined)
		}

		if (savedMatrix !== null) {
			await okJson(
				await admin.put('/index.php/apps/learniq/api/admin/action-matrix', {
					data: { matrix: savedMatrix },
				}),
				'restore matrix',
			)
		}

		for (const uid of Object.values(users)) {
			await admin
				.delete(`/ocs/v2.php/cloud/users/${uid}?format=json`)
				.catch(() => undefined)
		}
	})

	// @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-the-adr-023-matrix-gates-the-tool-before-any-domain-logic
	test('a user without the action right is refused and nothing is written', async () => {
		await chat(
			coordinator,
			ids.coordinatorAgent,
			`Call learniq_enrolLearner with learnerId "${users.pupil}" and courseId "${ids.renewalCourse}". Report the tool result.`,
		)

		expect(
			await find('enrolment', { courseId: ids.renewalCourse }),
			'a coordinator without mcp.enrol-learner enrolled a learner',
		).toHaveLength(0)
	})

	// @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-the-renewal-sweep-works-on-minimised-data-alone
	test('the renewal sweep lists the expiring certificate and enrols the learner', async () => {
		const before = new Date(Date.now() + 60 * 86400_000)
			.toISOString()
			.slice(0, 10)
		await chat(
			teacher,
			ids.teacherAgent,
			`Call learniq_listExpiringCredentials with before "${before}" and courseId "${ids.expiringCourse}". Then, for every credential in the result, call learniq_enrolLearner with that credential's learnerId, courseId "${ids.renewalCourse}" and reason "renewal". Report both results.`,
		)

		const enrolments = await find('enrolment', { courseId: ids.renewalCourse })
		expect(
			enrolments,
			'the sweep did not enrol exactly the learner with the expiring certificate',
		).toHaveLength(1)
		expect(enrolments[0].learnerId).toBe(users.pupil)
		expect(enrolments[0].lifecycle).toBe('pending')
		expect(String(enrolments[0].reason)).toContain('learniq.enrolLearner')
	})

	// @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-the-adr-023-matrix-gates-the-tool-before-any-domain-logic
	test('attendance is recorded in the name of the teacher', async () => {
		await chat(
			teacher,
			ids.teacherAgent,
			`Call learniq_recordAttendance with sessionId "${ids.session}", learnerId "${users.pupil}", status "absent-excused" and reason "Sick". Report the tool result.`,
		)

		const records = await find('attendance-record', { sessionId: ids.session })
		expect(records).toHaveLength(1)
		expect(records[0].status).toBe('absent-excused')
		expect(records[0].markedBy).toBe(users.teacher)
		expect(String(records[0].reason)).toContain('learniq.recordAttendance')
	})

	// @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#scenario-an-agent-proposed-grade-stays-invisible-until-a-teacher-publishes-it
	test('a batch of agent grades are concepts; the teacher publishes one and rejects the other', async () => {
		await chat(
			teacher,
			ids.teacherAgent,
			`Call learniq_gradeSubmission with submissionId "${ids.submission1}" and value 7.5, then call learniq_gradeSubmission with submissionId "${ids.submission2}" and value 6. Report both results.`,
		)

		const first = await find('grade-entry', { submissionId: ids.submission1 })
		const second = await find('grade-entry', { submissionId: ids.submission2 })
		expect(first, 'no concept grade for the first submission').toHaveLength(1)
		expect(second, 'no concept grade for the second submission').toHaveLength(1)
		for (const grade of [first[0], second[0]]) {
			expect(grade.lifecycle).toBe('concept')
			expect(grade.grader).toBe(users.teacher)
			expect(grade.curriculumPlanId).toBe(ids.plan)
			expect(grade.gradeScaleId).toBe(ids.scale)
		}

		const gradeId = first[0].id ?? first[0]['@self']?.id
		const pupilView = await okJson(
			await pupil.get(`${OR}/grade-entry?submissionId=${ids.submission1}`),
			'pupil reads grades',
		)
		expect(
			pupilView.results ?? [],
			'the learner sees a concept grade before a teacher published it',
		).toHaveLength(0)

		// Approved: the teacher publishes the first grade.
		await okJson(
			await teacher.post(
				`/index.php/apps/openregister/api/objects/${gradeId}/transition`,
				{ data: { action: 'publish' } },
			),
			'publish grade',
		)
		expect(
			(await find('grade-entry', { submissionId: ids.submission1 }))[0]
				.lifecycle,
		).toBe('published')

		// Rejected: the teacher removes the second concept; it was never published.
		const rejectedId = second[0].id ?? second[0]['@self']?.id
		expect((await teacher.delete(`${OR}/grade-entry/${rejectedId}`)).ok()).toBe(
			true,
		)
		expect(
			await find('grade-entry', { submissionId: ids.submission2 }),
		).toHaveLength(0)
	})
})
