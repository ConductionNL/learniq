/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — talk-classroom-spaces spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-coordinator-links-a-talk-conversation-to-a-cohort-as-its-persistent-class-space
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-teacher-links-a-sessions-call-to-the-parent-cohorts-existing-conversation
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-an-enrolled-learner-sees-and-can-use-the-join-call-action-on-a-session
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-a-session-without-a-linked-conversation-shows-no-dead-action
 *
 * The membership-sync bridge (CohortTalkMembershipHandler) and the
 * "Talk not installed degrades gracefully" scenario are backend/platform
 * behaviours verified by PHPUnit (CohortTalkMembershipHandlerTest) and
 * OpenRegister's own TalkLinkService::isTalkAvailable() + CnTalkCard
 * `degraded` surface (both pre-existing, unchanged by this app) — they
 * carry `@e2e exclude` on their spec scenarios.
 *
 * The linking/room-creation UX itself (picker, create-room dialog,
 * join-call action) lives entirely in OpenRegister's TalkLinksController and
 * nextcloud-vue's CnTalkTab/CnTalkCard/CnTalkRoomPicker/CnTalkRoomCreate —
 * this app adds zero Talk client code. This suite therefore does not
 * fabricate a full "create-and-link a room" flow (that would be testing the
 * shared library, not learniq); it asserts the one thing learniq's own
 * change is responsible for: the `integration`/`talk` widget declared on
 * CohortDetail (`cohort-talk`, manifest title "Class space") and SessionDetail
 * (`session-talk`, "Join call") is wired and mounts without a fatal error.
 *
 * Fixtures are DISCOVERED, as in adaptive-release.spec.ts /
 * progress-tracking.spec.ts — but a missing one only skips on an UNSEEDED
 * instance. On a seeded instance it fails: see requireFixture() for the run
 * where that distinction was worth an entire scenario.
 *
 * Those manifest titles are page-authoring labels and are NOT rendered — the
 * card draws its own header from the shared registry leaf. The anchors below
 * are CnTalkCard's own DOM instead; expectTalkWidget() carries the evidence.
 *
 * NC Talk (`spreed`) is NOT installed in the CI e2e job, which enables only
 * openregister. That is asserted as an explicit precondition rather than
 * skipped around: with Talk absent the widget must still mount and show its
 * empty/degraded line.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { requireFixture } from '../seeded.ts'

// `/index.php/` prefix is load-bearing on CI — a bare `php -S` does not rewrite
// pretty URLs, and `server/apps/openregister/` exists without an index.php, so
// the short form returns a hard 404. See adaptive-release.spec.ts.
// `_limit`, NOT `limit` — an unrecognised OpenRegister query parameter is
// applied as a PROPERTY FILTER rather than ignored, so `?limit=200` returns
// HTTP 200 with an empty result set and the guards below read it as "nothing
// seeded". Measured: ?_limit=200 -> 3 cohorts/sessions/enrolments present,
// ?limit=200 -> 0. This spec ran 0 of its 3 tests on every green CI run.
const COHORT_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/Cohort?_limit=200'
const SESSION_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/Session?_limit=200'
const ENROLMENT_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/Enrolment?_limit=200'

/**
 * Fetch every row for a schema's index endpoint and return the first one
 * matching the given predicate, or null when none exists in this environment.
 *
 * @param page    The Playwright page (used for its authenticated request context).
 * @param url     The OpenRegister object-list API URL.
 * @param matches Predicate a candidate row must satisfy.
 */
async function findRow(page: Page, url: string, matches: (_row: any) => boolean) {
	const resp = await page.request.get(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const rows = json.results ?? json.objects ?? json ?? []
	// ⚠️ NO `?? rows[0]` fallback. It used to sit here and it made the
	// `matches` predicate VACUOUS: the enrolment-scoped test below asks for the
	// Session belonging to a specific Cohort, and on a miss the fallback handed
	// back an arbitrary Session instead. The test would then have asserted
	// against the wrong object while its `test.skip(!session, …)` guard — the
	// thing that is supposed to say "this environment cannot answer the
	// question" — could never fire. A predicate that cannot fail to match is
	// not a predicate.
	return rows.find(matches) ?? null
}

function collectFatalErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') errors.push(msg.text())
	})
	return errors
}

function fatalOnly(errors: string[]): string[] {
	return errors.filter(
		(e) =>
			!e.includes('favicon')
			&& !e.includes('font')
			&& !e.includes('Failed to load resource')
			&& !e.includes('net::ERR_ABORTED')
			&& !e.includes('Failed to fetch')
			&& !e.includes('ERR_CONNECTION_REFUSED'),
	)
}

/**
 * Anything CnTalkCard renders on the `detail-page` surface once it has
 * settled: the linked-conversation list, or the single empty/degraded line it
 * shows when there are no rooms (or when OpenRegister answers 503 because Talk
 * is not installed). Only the `talk` integration leaf emits these classes, so
 * matching one proves THIS widget mounted — not merely that some card did.
 *
 * Verified against @conduction/nextcloud-vue 2.15.0, the version
 * package-lock.json resolves and CI installs:
 * src/integrations/builtin/talk/CnTalkCard.vue lines 69-75.
 */
const TALK_CARD_BODY = '.cn-talk-card__list, .cn-talk-card__empty'

/**
 * Open an in-app route and prove the SPA actually rendered a detail page there.
 *
 * ⚠️ NO `#` — the router is HISTORY mode, not hash mode.
 *
 * src/main.js builds it with `createWebHistory(generateUrl('/apps/learniq'))`.
 * vue-router strips that base from `location.pathname` and appends the
 * UNTOUCHED hash, so `/index.php/apps/learniq/sessions/<id>` resolved to
 * `/#/sessions/<id>`, matched no declared route, and fell through
 * `routesFromManifest`'s `/:pathMatch(.*)*` catch-all — which `redirect: '/'`s
 * to the DASHBOARD. Every failure screenshot in CI run 32833668787 shows
 * "Administrator · Dashboard". detail-pages.spec.ts, index-pages.spec.ts and
 * accessibility-conformance.spec.ts all use the plain path form for this
 * reason; the last one carries the same warning in prose.
 *
 * @param page  The Playwright page.
 * @param route In-app route path, e.g. `/sessions/<id>`.
 */
async function openRoute(page: Page, route: string) {
	await page.goto(`/index.php/apps/learniq${route}`, {
		waitUntil: 'domcontentloaded',
	})

	// The catch-all rewrites the URL on a fall-through, so this names the
	// failure ("we are on the dashboard") instead of leaving a bare timeout on
	// whatever the next assertion happened to be.
	//
	// Matched on the collection segment (`/cohorts/`, `/sessions/`) rather than
	// the whole route: the detail page may canonicalise the id in the URL, and
	// what this guards against is the fall-through to `/` — the dashboard's
	// pathname is the bare app base and carries no collection segment at all.
	const collection = `/${route.split('/').filter(Boolean)[0]}/`
	await expect
		.poll(() => new URL(page.url()).pathname, {
			message: 'router fell through to the dashboard — no route matched',
			timeout: 10_000,
		})
		.toContain(collection)

	// A manifest `type: "detail"` page renders through CnPageRenderer →
	// CnDetailPage, whose root carries this testid. The dashboard renders
	// CnDashboardPage and does not, so reaching here rules out the fall-through
	// even if a future route made the URL check pass on its own.
	await expect(page.locator('[data-testid="cn-detail-page"]')).toBeVisible({
		timeout: 20_000,
	})
}

/**
 * Whether NC Talk (`spreed`) is installed on the instance under test.
 *
 * The CI e2e job installs exactly one additional app — openregister (see
 * `additional-apps` in .github/workflows) — so Talk is ABSENT there. That is a
 * precondition of the environment, not of this app, and it is asserted rather
 * than silently skipped: with Talk gone the widget must still mount and show
 * its empty/degraded line, which is the "degrades gracefully" half of the
 * spec.
 *
 * @param page The Playwright page (used for its authenticated request context).
 * @return true when the capabilities payload advertises `spreed`.
 */
async function isTalkInstalled(page: Page): Promise<boolean> {
	const resp = await page.request.get(
		'/ocs/v2.php/cloud/capabilities?format=json',
		{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
	)
	if (!resp.ok()) return false

	const json = await resp.json()
	return Boolean(json?.ocs?.data?.capabilities?.spreed)
}

/**
 * Assert the manifest's `type: "integration"` / `integrationId: "talk"` widget
 * mounted on the detail page currently open.
 *
 * ⚠️ This does NOT assert the manifest widget `title` ("Class space" /
 * "Join call"), which is what this suite used to do. That string is never
 * rendered anywhere, on any page — the assertion could not have passed even
 * with the routing above fixed:
 *
 *   - CnDetailPage.getIntegrationProps() passes only `surface`, the object
 *     context and the widget def's `props` to the integration component. The
 *     def's `title` is not among them.
 *   - The grid's own `<h3>` is the other title path, and `showGridTitle()`
 *     returns false for `showTitle: false` — which both layout entries set
 *     (src/manifest.d/learning.json, items `7` on CohortDetail and
 *     SessionDetail) — and in any case only renders for consumer-supplied
 *     `#widget-<id>` slots.
 *   - CnTalkCard therefore draws its own header from the registry leaf's
 *     label, `t('nextcloud-vue', 'Chat')`.
 *
 * All three read from @conduction/nextcloud-vue 2.15.0, the resolved version.
 * The manifest title is a page-authoring label; the rendered card title
 * belongs to the shared library. What learniq's change is responsible for —
 * and what this asserts — is that the widget is WIRED onto these two detail
 * pages and mounts. Remove either widget from learning.json and this fails.
 *
 * @param page          The Playwright page, already on the detail route.
 * @param talkInstalled Whether `spreed` is installed on this instance.
 */
async function expectTalkWidget(page: Page, talkInstalled: boolean) {
	await expect(page.locator(TALK_CARD_BODY).first()).toBeVisible({
		timeout: 15_000,
	})

	if (!talkInstalled) {
		// Without Talk, OpenRegister's talk sub-resource cannot answer with
		// rooms, so the card must be in its empty/degraded state — mounted and
		// speaking, not crashed and not pretending to list conversations.
		await expect(page.locator('.cn-talk-card__list')).toHaveCount(0)
		await expect(page.locator('.cn-talk-card__empty').first()).toBeVisible()
	}
}

test.describe('talk-classroom-spaces — Cohort class-space widget', () => {
	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-coordinator-links-a-talk-conversation-to-a-cohort-as-its-persistent-class-space
	test('cohort detail renders the "Class space" talk widget', async ({
		loggedInPage: page,
	}) => {
		const cohort = await findRow(page, COHORT_LIST_API, () => true)
		requireFixture(cohort, 'a Cohort')

		const talkInstalled = await isTalkInstalled(page)
		test.info().annotations.push({
			type: 'precondition',
			description: `NC Talk (spreed) installed: ${talkInstalled}`,
		})

		const errors = collectFatalErrors(page)
		const cohortId = cohort.id ?? cohort.uuid
		await openRoute(page, `/cohorts/${cohortId}`)

		// The `cohort-talk` widget in src/manifest.d/learning.json is what this
		// app's change added; that it mounts here is what this asserts. See
		// expectTalkWidget() for why the manifest title is not the anchor.
		await expectTalkWidget(page, talkInstalled)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})

test.describe('talk-classroom-spaces — Session join-call widget', () => {
	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-teacher-links-a-sessions-call-to-the-parent-cohorts-existing-conversation
	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-a-session-without-a-linked-conversation-shows-no-dead-action
	test('session detail renders the "Join call" talk widget', async ({
		loggedInPage: page,
	}) => {
		const session = await findRow(page, SESSION_LIST_API, () => true)
		requireFixture(session, 'a Session')

		const talkInstalled = await isTalkInstalled(page)
		test.info().annotations.push({
			type: 'precondition',
			description: `NC Talk (spreed) installed: ${talkInstalled}`,
		})

		const errors = collectFatalErrors(page)
		const sessionId = session.id ?? session.uuid
		await openRoute(page, `/sessions/${sessionId}`)

		// The `session-talk` widget in src/manifest.d/learning.json.
		await expectTalkWidget(page, talkInstalled)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-an-enrolled-learner-sees-and-can-use-the-join-call-action-on-a-session
	test('a session tied to a cohort with active enrolments still renders the join-call widget', async ({
		loggedInPage: page,
	}) => {
		const activeEnrolment = await findRow(
			page,
			ENROLMENT_LIST_API,
			(e) => e.lifecycle === 'active' && !!e.cohortId,
		)
		requireFixture(activeEnrolment, 'an active Enrolment with a cohortId')

		const session = await findRow(
			page,
			SESSION_LIST_API,
			(s) => s.cohortId === activeEnrolment.cohortId,
		)
		requireFixture(session, "a Session belonging to that learner's Cohort")

		// Guards the premise of this test rather than trusting it: `findRow` no
		// longer falls back to an arbitrary row, so a Session reaching here
		// really does belong to the enrolled learner's Cohort.
		expect(
			session.cohortId,
			"the Session under test must belong to the enrolled learner's Cohort",
		).toBe(activeEnrolment.cohortId)

		const talkInstalled = await isTalkInstalled(page)
		test.info().annotations.push({
			type: 'precondition',
			description: `NC Talk (spreed) installed: ${talkInstalled}`,
		})

		const errors = collectFatalErrors(page)
		const sessionId = session.id ?? session.uuid
		await openRoute(page, `/sessions/${sessionId}`)

		// The widget is present for every viewer per the Session's existing
		// RBAC (teacher/coordinator/enrolled learner) — the admin session
		// used by this suite has access to every object, so this asserts
		// the widget renders on a Session that genuinely has an enrolled
		// learner in scope, not just an arbitrary one.
		await expectTalkWidget(page, talkInstalled)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})
