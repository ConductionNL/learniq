// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * An mbo external assessor reads how long his access runs and opens what was
 * shared with him (site-external-assessor-portal-design T7).
 *
 * assessor-flows.spec.ts proves the share list and that a revoked or foreign
 * share grants nothing. This suite proves the rest of the change: the share of
 * a selection opens the selected entries and only those
 * (`eaSharedPortfolioEntries`, through the share's `entryIds`), the share of a
 * whole portfolio opens all its entries (`eaWholePortfolioEntries`, through the
 * server-stamped `wholePortfolioId`), and the share's page says until when his
 * access runs, or that it has no end date.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, one external
 * assessor, two portfolios with two entries each, two active shares (a
 * selection until 30 June 2099, a whole portfolio without an end date) and the
 * portal account carrying the `externalAssessorId` claim. All of it is removed
 * in `afterAll`. It does not lean on the mbo example set's seeded shares,
 * whose fixed end dates pass.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts mbo-assessor-flows
 *
 * Screenshots land in `test-results/audience-flow/mbo-assessor/`.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-an-external-assessor-opens-the-shared-entries-and-only-those
 */

import type { APIRequestContext } from '@playwright/test'
import type { PortalLogin, SeededRow } from './helpers/portal-fixture.ts'

import { expect, test } from '@playwright/test'
import * as path from 'node:path'
import {
	asUser,
	borrowLearnerProfile,
	borrowPublishedHomework,
	createNextcloudAccount,
	createRow,
	grantPortalAccount,
	nextcloudAccountExists,
	openRoute,
	portalRows,
	removeNextcloudAccount,
	removeRows,
	requireSignInMode,
	shot,
	signInWithNextcloudAccount,
} from './helpers/portal-fixture.ts'

const ENABLED = process.env.AUDIENCE_FLOW_E2E === '1'
const SHOTS = path.resolve(
	__dirname,
	'..',
	'..',
	'test-results',
	'audience-flow',
	'mbo-assessor',
)
const PORTAL = process.env.AUDIENCE_FLOW_PORTAL ?? 'wilgenboom'
const TENANT =
	process.env.AUDIENCE_FLOW_TENANT ?? '00000000-0000-4000-8000-000000000000'
const ADMIN = {
	user: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

const RUN = Date.now().toString(36)
const ASSESSOR = {
	user: `lq-e2e-examinator-${RUN}`,
	pass: `Lq-e2e-${RUN}-examinator!`,
	given: 'Henk',
	family: 'de Vries',
	organisationName: `Examenbureau Rivierland (${RUN})`,
}
const SELECTED_TITLE = `Examenportfolio installatietechniek (${RUN})`
const WHOLE_TITLE = `Examenportfolio werkplaats (${RUN})`

test.describe.configure({ mode: 'serial' })

test.describe('mbo assessor: his access date and the shared entries', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let assessor: PortalLogin
	let selectedShareId = ''
	let wholeShareId = ''
	let selectedEntryId = ''
	let leftOutEntryId = ''
	const wholeEntryIds: string[] = []
	let candidate = ''
	let createdAccount = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)
		const organisation = (await requireSignInMode(admin, PORTAL, 'nextcloud')).organisation

		test.skip(
			(await nextcloudAccountExists(admin, ASSESSOR.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(admin, ASSESSOR, `${ASSESSOR.given} ${ASSESSOR.family}`)
		createdAccount = true

		const homework = await borrowPublishedHomework(admin, [
			'ee010008-0000-4000-8000-000000000415',
			'ee010008-0000-4000-8000-000000000467',
		])
		test.skip(homework === undefined, 'this instance has no pupils to borrow')
		const learnerRef = homework!.learnerRefs[0]
		const profile = await borrowLearnerProfile(admin, learnerRef)
		test.skip(profile === undefined, 'the borrowed candidate has no profile')
		const learnerId = String(profile!.ncUserId ?? '')

		const person = await createRow(admin, created, 'learniq', 'external-assessor', {
			givenName: ASSESSOR.given,
			familyName: ASSESSOR.family,
			email: `${ASSESSOR.user}@example.org`,
			organisationName: ASSESSOR.organisationName,
			active: true,
			tenant_id: TENANT,
		})
		const assessorRef = String(person.id)

		// A portfolio shared in part: one entry selected, one left out.
		const selected = await createRow(admin, created, 'learniq', 'portfolio', {
			learnerId, learnerRef, kind: 'personal', title: SELECTED_TITLE, lifecycle: 'active', tenant_id: TENANT,
		})
		for (const title of ['Werkplekopdracht: meterkast vervangen', 'Reflectie op de stageweek']) {
			const entry = await createRow(admin, created, 'learniq', 'portfolio-entry', {
				portfolioId: String(selected.id), learnerId, title, evidenceKind: 'reflection',
				reflectionText: `${title}.`, tenant_id: TENANT,
			})
			if (selectedEntryId === '') {
				selectedEntryId = String(entry.id)
			} else {
				leftOutEntryId = String(entry.id)
			}
		}
		const partShare = await createRow(admin, created, 'learniq', 'portfolio-share', {
			portfolioId: String(selected.id),
			entryIds: [selectedEntryId],
			sharedWithKind: 'external-assessor',
			sharedWithExternalAssessorId: assessorRef,
			sharedBy: 'admin',
			expiresAt: '2099-06-30T23:59:59+00:00',
			lifecycle: 'active',
			tenant_id: TENANT,
		})
		selectedShareId = String(partShare.id)

		// A whole portfolio shared without an end date.
		const whole = await createRow(admin, created, 'learniq', 'portfolio', {
			learnerId, learnerRef, kind: 'personal', title: WHOLE_TITLE, lifecycle: 'active', tenant_id: TENANT,
		})
		for (const title of ['Proeve: storing zoeken', 'Proeve: oplevering']) {
			const entry = await createRow(admin, created, 'learniq', 'portfolio-entry', {
				portfolioId: String(whole.id), learnerId, title, evidenceKind: 'reflection',
				reflectionText: `${title}.`, tenant_id: TENANT,
			})
			wholeEntryIds.push(String(entry.id))
		}
		const wholeShare = await createRow(admin, created, 'learniq', 'portfolio-share', {
			portfolioId: String(whole.id),
			entryIds: [],
			sharedWithKind: 'external-assessor',
			sharedWithExternalAssessorId: assessorRef,
			sharedBy: 'admin',
			lifecycle: 'active',
			tenant_id: TENANT,
		})
		wholeShareId = String(wholeShare.id)

		// What `occ learniq:portal:invite-assessor` writes (learniq#1680).
		await grantPortalAccount(admin, created, {
			subjectRef: ASSESSOR.user,
			audience: 'external-assessor',
			organisation,
			email: `${ASSESSOR.user}@example.org`,
			displayName: `${ASSESSOR.given} ${ASSESSOR.family}`,
			claims: { learniq: { externalAssessorId: assessorRef } },
		})

		assessor = await signInWithNextcloudAccount(browser, ASSESSOR, PORTAL, SHOTS)
	})

	test.afterAll(async () => {
		await removeRows(admin, created, 'mbo-assessor-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, ASSESSOR.user)
		}

		await assessor?.page.close()
	})

	test('a. both shares are his, with the date his access runs to, and none for the open-ended one', async () => {
		const shares = await portalRows(assessor, 'portfolio-share', 'eaSharedPortfolios')
		const part = shares.find((row) => String(row.id) === selectedShareId)
		const whole = shares.find((row) => String(row.id) === wholeShareId)
		expect(part, 'the share of a selection is listed').toBeTruthy()
		expect(whole, 'the share of a whole portfolio is listed').toBeTruthy()
		expect(String(part?.expiresAt ?? '')).toContain('2099-06-30')
		expect(whole?.expiresAt ?? null, 'the open-ended share carries no end date').toBeFalsy()

		candidate = String(part?.learnerName ?? '')
		expect(candidate, 'the share names its candidate').not.toBe('')
		expect(String(part?.portfolioTitle ?? '')).toBe(SELECTED_TITLE)
		expect(String(whole?.portfolioTitle ?? '')).toBe(WHOLE_TITLE)
	})

	test('b. he opens the entry that was selected, and not the one left out', async () => {
		const entries = await portalRows(assessor, 'portfolio-entry', 'eaSharedPortfolioEntries')
		const ids = entries.map((row) => String(row.id))
		expect(ids).toContain(selectedEntryId)
		expect(ids, 'an entry the share did not select stays closed').not.toContain(leftOutEntryId)
		for (const id of wholeEntryIds) {
			expect(ids, 'the selection collection holds no entry of the whole-portfolio share').not.toContain(id)
		}

		const opened = await assessor.page.request.get(
			`/apps/portaliq/portal/api/collections/learniq/portfolio-entry/${selectedEntryId}?collection=eaSharedPortfolioEntries`,
			{ headers: { Authorization: `Bearer ${assessor.token}` } },
		)
		expect(opened.status(), await opened.text()).toBe(200)
		// portaliq answers one collection row as `{object: {...}}`.
		const body = await opened.json()
		expect(String((body.object ?? body).title ?? '')).toBe('Werkplekopdracht: meterkast vervangen')

		const closed = await assessor.page.request.get(
			`/apps/portaliq/portal/api/collections/learniq/portfolio-entry/${leftOutEntryId}?collection=eaSharedPortfolioEntries`,
			{ headers: { Authorization: `Bearer ${assessor.token}` } },
		)
		expect(closed.status()).toBeGreaterThanOrEqual(400)
	})

	test('c. a whole portfolio shared without an end date opens every entry of it', async () => {
		const entries = await portalRows(assessor, 'portfolio-entry', 'eaWholePortfolioEntries')
		const ids = entries.map((row) => String(row.id))
		for (const id of wholeEntryIds) {
			expect(ids).toContain(id)
		}
		expect(ids, 'the partly shared portfolio does not open whole').not.toContain(leftOutEntryId)
	})

	test('d. on the page he reads until when, and the entry shared with him', async () => {
		// The share's own page (AssessorSitePages::sharePage), opened by its
		// route: a row of "Met u gedeeld" opens the share's fields in place.
		await openRoute(assessor.page, PORTAL, `learniq/eaShare/${selectedShareId}`)
		await expect(assessor.page.getByText(/U heeft toegang tot en met/).first()).toBeVisible({ timeout: 20_000 })
		await expect(assessor.page.getByText('Werkplekopdracht: meterkast vervangen').first()).toBeVisible()
		await expect(assessor.page.getByText('Reflectie op de stageweek')).toHaveCount(0)
		await shot(assessor.page, SHOTS, 'd1-share-page')
	})
})
