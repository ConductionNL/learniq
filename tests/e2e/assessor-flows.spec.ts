// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * An external assessor's portal, end to end.
 *
 * He assesses a candidate's portfolio for an examination board, from outside
 * the school. The school invites him and he signs in with the account it gave
 * him (portaliq's `nextcloud` mode, trust `low`), exactly as the workplace
 * trainer does. His portal is read only: the manifest gives him no action at
 * all, and what he may read is one list of the shares granted to him.
 *
 * WHAT THIS SUITE CREATES AND REMOVES. Its own Nextcloud account, one external
 * assessor, one portfolio with an entry, three shares (one active, one
 * revoked, one that belongs to somebody else) and the portal account carrying
 * the `externalAssessorId` claim. All of it is removed in `afterAll`, together
 * with the portal's original list of sign-in modes.
 *
 *   AUDIENCE_FLOW_E2E=1 PLAYWRIGHT_BASE_URL=http://localhost:8090 \
 *   npx playwright test --config tests/e2e/audience-flow.config.ts assessor-flows
 *
 * Flows:
 *   a. he signs in and lands on an overview listing what is shared with him,
 *      by candidate, portfolio and the date his access runs to;
 *   b. a revoked share grants nothing, and neither does another assessor's;
 *   c. his portal is read only: it offers no action, and a write is refused.
 *
 * Screenshots land in `test-results/audience-flow/assessor/`.
 *
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
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
	offerSignInMode,
	openRoute,
	portalRows,
	removeNextcloudAccount,
	removeRows,
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
	'assessor',
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
	user: `lq-e2e-beoordelaar-${RUN}`,
	pass: `Lq-e2e-${RUN}-beoordelaar!`,
	given: 'Ruud',
	family: 'Jansen',
	organisationName: `Examinering Zuiddrecht (${RUN})`,
}
const PORTFOLIO_TITLE = `Examenportfolio installatietechniek (${RUN})`

test.describe.configure({ mode: 'serial' })

test.describe('assessor: a portfolio shared with an external assessor', () => {
	test.skip(
		!ENABLED,
		'set AUDIENCE_FLOW_E2E=1 on an instance with portaliq and a portal for the school',
	)

	const created: SeededRow[] = []
	let admin: APIRequestContext
	let restoreModes: () => Promise<void> = async () => undefined
	let assessor: PortalLogin
	let assessorRef = ''
	let activeShareId = ''
	let revokedShareId = ''
	let foreignShareId = ''
	let createdAccount = false

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await asUser(ADMIN)

		const mode = await offerSignInMode(admin, PORTAL, 'nextcloud')
		restoreModes = mode.restore
		const organisation = mode.organisation

		test.skip(
			(await nextcloudAccountExists(admin, ASSESSOR.user)) === true,
			'the instance already has an account with this run id',
		)
		await createNextcloudAccount(
			admin,
			ASSESSOR,
			`${ASSESSOR.given} ${ASSESSOR.family}`,
		)
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

		const person = await createRow(
			admin,
			created,
			'learniq',
			'external-assessor',
			{
				givenName: ASSESSOR.given,
				familyName: ASSESSOR.family,
				email: `${ASSESSOR.user}@example.org`,
				organisationName: ASSESSOR.organisationName,
				active: true,
				tenant_id: TENANT,
			},
		)
		assessorRef = String(person.id)

		const portfolio = await createRow(admin, created, 'learniq', 'portfolio', {
			learnerId,
			learnerRef,
			kind: 'personal',
			title: PORTFOLIO_TITLE,
			lifecycle: 'active',
			tenant_id: TENANT,
		})

		const entry = await createRow(admin, created, 'learniq', 'portfolio-entry', {
			portfolioId: String(portfolio.id),
			learnerId,
			title: 'Werkplekopdracht: meterkast vervangen',
			evidenceKind: 'reflection',
			reflectionText: 'Ik heb de meterkast zelfstandig vervangen.',
			tenant_id: TENANT,
		})

		// What he may read: one active grant, until a date he can see.
		const active = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				entryIds: [String(entry.id)],
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: assessorRef,
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'active',
				tenant_id: TENANT,
			},
		)
		activeShareId = String(active.id)

		// What he may not: a grant the school took back.
		const revoked = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: assessorRef,
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'revoked',
				tenant_id: TENANT,
			},
		)
		revokedShareId = String(revoked.id)

		// And what belongs to another assessor entirely.
		const other = await createRow(
			admin,
			created,
			'learniq',
			'external-assessor',
			{
				givenName: 'Iemand',
				familyName: 'Anders',
				email: `lq-e2e-ander-${RUN}@example.org`,
				organisationName: 'Een ander bureau',
				active: true,
				tenant_id: TENANT,
			},
		)
		const foreign = await createRow(
			admin,
			created,
			'learniq',
			'portfolio-share',
			{
				portfolioId: String(portfolio.id),
				sharedWithKind: 'external-assessor',
				sharedWithExternalAssessorId: String(other.id),
				sharedBy: 'admin',
				expiresAt: '2026-12-31T23:59:59+00:00',
				lifecycle: 'active',
				tenant_id: TENANT,
			},
		)
		foreignShareId = String(foreign.id)

		// The claim his list is scoped by. This is what
		// `occ learniq:portal:invite-assessor` writes (learniq#1680).
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
		await restoreModes()
		await removeRows(admin, created, 'assessor-flow')
		if (createdAccount === true) {
			await removeNextcloudAccount(admin, ASSESSOR.user)
		}

		await assessor?.page.close()
	})

	test('a. he lands on what is shared with him, and until when', async () => {
		await shot(assessor.page, SHOTS, 'a1-overview')
		const shares = await portalRows(
			assessor,
			'portfolio-share',
			'eaSharedPortfolios',
		)
		expect(shares.map((row) => String(row.id))).toContain(activeShareId)

		// The share names its candidate and its portfolio, so he reads a person
		// rather than a uuid. Both are readable copies the server stamps.
		const mine = shares.find((row) => String(row.id) === activeShareId)
		expect(String(mine?.portfolioTitle ?? '')).toBe(PORTFOLIO_TITLE)
		expect(String(mine?.learnerName ?? '')).not.toBe('')
		await expect(assessor.page.getByText(PORTFOLIO_TITLE).first()).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			assessor.page.getByText(String(mine?.learnerName)).first(),
		).toBeVisible()
	})

	test('b. a revoked share grants nothing, and neither does another assessor s', async () => {
		const shares = await portalRows(
			assessor,
			'portfolio-share',
			'eaSharedPortfolios',
		)
		const ids = shares.map((row) => String(row.id))
		expect(ids).not.toContain(revokedShareId)
		expect(ids).not.toContain(foreignShareId)
		// Every row he reads is granted to him and still active.
		for (const row of shares) {
			expect(row.lifecycle).toBe('active')
		}

		for (const id of [revokedShareId, foreignShareId]) {
			const direct = await assessor.page.request.get(
				`/apps/portaliq/portal/api/collections/learniq/portfolio-share/${id}?collection=eaSharedPortfolios`,
				{ headers: { Authorization: `Bearer ${assessor.token}` } },
			)
			expect(direct.status()).toBeGreaterThanOrEqual(400)
		}

		await openRoute(assessor.page, PORTAL, 'learniq/eaSharedPortfolios')
		await shot(assessor.page, SHOTS, 'b1-shares')
	})

	test('c. his portal is read only', async () => {
		const manifest = await assessor.page.request.get(
			'/apps/portaliq/portal/api/contributions',
			{ headers: { Authorization: `Bearer ${assessor.token}` } },
		)
		expect(manifest.status(), await manifest.text()).toBe(200)
		const body = await manifest.json()
		expect(body.audience).toBe('external-assessor')
		for (const contribution of body.contributions ?? []) {
			expect(contribution.actions ?? []).toEqual([])
		}

		// And a write he was never offered is refused rather than stored.
		const written = await assessor.page.request.post(
			'/apps/portaliq/portal/api/collections/learniq/portfolio-share',
			{
				headers: { Authorization: `Bearer ${assessor.token}` },
				data: {
					portfolioId: '00000000-0000-4000-8000-00000000dead',
					sharedWithKind: 'external-assessor',
					sharedBy: ASSESSOR.user,
				},
			},
		)
		expect(written.status()).toBeGreaterThanOrEqual(400)
	})
})
