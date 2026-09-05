/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — progress-tracking spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
 *   @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-manual-completion-is-not-available-for-xapi-instrumented-content
 *
 * LessonCompletion persistence/RBAC, the xAPI-sourced LessonProgressHandler
 * wiring, and the duplicate-statement upsert behaviour are all backend
 * behaviours verified by PHPUnit (LessonProgressHandlerTest) and schema-
 * validation — they carry `@e2e exclude` on their respective scenarios in
 * the spec. Here we assert the one frontend surface this change adds: the
 * "Mark lesson complete" action on LessonPlayer.vue, present for a
 * non-xAPI-instrumented Lesson (contentType text/video/quiz) and absent for
 * an xAPI-instrumented one (contentType cmi5/scorm12/scorm2004).
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 * Both scenarios discover a real Lesson of the required contentType via the
 * OpenRegister object API rather than assuming a specific seeded UUID — a
 * scenario is skipped (not failed) when the seeded dev instance carries no
 * Lesson of that contentType, mirroring the PHPUnit integration suite's own
 * markTestSkipped convention for environment-dependent fixtures.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { requireFixture } from '../seeded.ts'

// `/index.php/` prefix is load-bearing on CI — a bare `php -S` does not rewrite
// pretty URLs, and `server/apps/openregister/` exists without an index.php, so
// the short form returns a hard 404. See adaptive-release.spec.ts.
// `_limit`, NOT `limit` — an unrecognised OpenRegister query parameter is
// applied as a PROPERTY FILTER rather than ignored, so `?limit=200` returns
// HTTP 200 with an empty result set and the guards below read it as "no
// published Lesson seeded". This spec ran 0 of its 2 tests on every green run.
const LESSON_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/Lesson?_limit=200'

/**
 * Fetch every Lesson and return the first one matching the given predicate,
 * or null when none exists in this environment.
 *
 * @param page    The Playwright page (used for its authenticated request context).
 * @param matches Predicate a candidate Lesson row must satisfy.
 */
async function findLesson(page: Page, matches: (_lesson: any) => boolean) {
	const resp = await page.request.get(LESSON_LIST_API, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const lessons = json.results ?? json.objects ?? json ?? []
	return lessons.find(matches) ?? null
}

/**
 * The UUID for the parent Course of a Lesson row, or null.
 *
 * @param lesson The Lesson row.
 */
function courseIdOf(lesson: any): string | null {
	return lesson?.courseId ?? null
}

test.describe('learning-progress-and-analytics — Lesson manual-completion action', () => {
	// @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
	test('a text lesson shows the "Mark lesson complete" action and it can be used', async ({
		loggedInPage: page,
	}) => {
		// ⚠️ UNGATED, EXPLICITLY. This asked only for text + published, which was
		// unambiguous while exactly one such Lesson existed. It is not any more:
		// the seeder now also creates a release-gated and a drip-delayed text
		// Lesson for adaptive-release, and a gated Lesson renders LessonPlayer's
		// locked branch — which has no footer, and therefore no
		// "Mark lesson complete" button. Whichever row the API returned first
		// would decide whether this test passed.
		//
		// The scenario is about manual completion, not about gating, so it now
		// says so: same ungated shape adaptive-release.spec.ts uses.
		const lesson = await findLesson(
			page,
			(l) =>
				l.contentType === 'text'
				&& l.lifecycle === 'published'
				&& (l.releaseConditions === null
					|| l.releaseConditions === undefined
					|| l.releaseConditions.length === 0)
				&& (l.availableAfterDays === null
					|| l.availableAfterDays === undefined),
		)
		const courseId = courseIdOf(lesson)
		requireFixture(
			lesson && courseId,
			'a published, ungated contentType=text Lesson with a courseId',
		)

		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})

		const lessonId = lesson.id ?? lesson.uuid
		await page.goto(
			// ⚠️ NO `#` — HISTORY mode router (fixed fleet-wide in #610). With
			// the hash this resolved to `/#/courses/…/play`, matched no route,
			// and the catch-all redirected to the DASHBOARD — which is why
			// neither "Mark lesson complete" nor "Completed" was ever visible.
			`/index.php/apps/learniq/courses/${courseId}/lessons/${lessonId}/play`,
		)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		const markButton = page.getByRole('button', { name: 'Mark lesson complete' })
		const alreadyCompleted = page.getByRole('button', { name: 'Completed' })

		// Either the action is available (not yet completed by this session's
		// user) or it already reads "Completed" from a prior run — both prove
		// the action IS rendered for a non-xAPI content type.
		const hasMarkButton = await markButton
			.isVisible({ timeout: 15_000 })
			.catch(() => false)
		const hasCompletedButton = await alreadyCompleted
			.isVisible({ timeout: 5_000 })
			.catch(() => false)
		expect(
			hasMarkButton || hasCompletedButton,
			'expected either "Mark lesson complete" or "Completed" to be visible',
		).toBeTruthy()

		if (hasMarkButton) {
			await markButton.click()
			await expect(alreadyCompleted).toBeVisible({ timeout: 15_000 })
		}

		const fatal = errors.filter(
			(e) =>
				!e.includes('favicon')
				&& !e.includes('font')
				&& !e.includes('Failed to load resource')
				&& !e.includes('net::ERR_ABORTED')
				&& !e.includes('Failed to fetch')
				&& !e.includes('ERR_CONNECTION_REFUSED'),
		)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-manual-completion-is-not-available-for-xapi-instrumented-content
	test('a cmi5 lesson does not show the "Mark lesson complete" action', async ({
		loggedInPage: page,
	}) => {
		// ⚠️ UNGATED TOO, AND FOR A SHARPER REASON THAN ABOVE. This scenario
		// asserts the button is ABSENT — and a release-gated lesson shows no
		// button either, because LessonPlayer renders its locked branch instead
		// of the footer. Selecting a gated cmi5 Lesson would therefore make this
		// test pass for entirely the wrong reason, proving nothing about
		// contentType. Requiring an ungated one keeps the absence attributable.
		const lesson = await findLesson(
			page,
			(l) =>
				l.contentType === 'cmi5'
				&& l.lifecycle === 'published'
				&& (l.releaseConditions === null
					|| l.releaseConditions === undefined
					|| l.releaseConditions.length === 0)
				&& (l.availableAfterDays === null
					|| l.availableAfterDays === undefined),
		)
		const courseId = courseIdOf(lesson)
		requireFixture(
			lesson && courseId,
			'a published, ungated contentType=cmi5 Lesson with a courseId',
		)

		const lessonId = lesson.id ?? lesson.uuid
		await page.goto(
			// ⚠️ NO `#` — HISTORY mode router (fixed fleet-wide in #610). With
			// the hash this resolved to `/#/courses/…/play`, matched no route,
			// and the catch-all redirected to the DASHBOARD — which is why
			// neither "Mark lesson complete" nor "Completed" was ever visible.
			`/index.php/apps/learniq/courses/${courseId}/lessons/${lessonId}/play`,
		)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		const markButton = page.getByRole('button', { name: 'Mark lesson complete' })
		await expect(markButton).toHaveCount(0)
	})
})
