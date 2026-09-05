/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — course-authoring-ux spec UI scenarios.
 *
 * Covers (UI-observable surface), matching the spec's own `@e2e` tags:
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-an-instructional-designer-composes-a-lesson-from-mixed-blocks
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-media-block-references-an-existing-material-rather-than-duplicating-file-metadata
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-lessons-within-a-course-by-drag-and-drop
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-lessons-within-a-course-using-only-the-keyboard
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-blocks-within-a-lesson-using-only-the-keyboard
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-designer-sets-module-order-in-the-course-builder
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-an-instructional-designer-saves-a-published-course-as-a-template
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-instantiating-a-template-creates-a-fresh-independent-course-tree
 *   @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-learner-opens-a-native-lesson-and-sees-its-composed-blocks-in-order
 *
 * The conditional contentRef requiredness (allOf/if/then) is a schema-level
 * regression covered by PHPUnit (CourseAuthoringRegisterTest) — it carries
 * `@e2e exclude` reasoning in the spec for exactly that reason, but this
 * suite still relies on that schema being live for the create-block flows.
 *
 * Mirroring adaptive-release.spec.ts's own convention: discover a real
 * Course via the OpenRegister object API through the authenticated
 * session, then use CourseBuilder/LessonComposer's OWN UI to create the
 * module/lesson/block fixtures each scenario needs — "UI-driven creation or
 * discover-and-skip are the two established patterns" (no raw API POST
 * fixture creation). A scenario is skipped (not failed) when the seeded
 * dev instance carries no Course at all.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { requireFixture } from '../seeded.ts'

// `/index.php/` prefix is load-bearing on CI — a bare `php -S` does not rewrite
// pretty URLs, and `server/apps/openregister/` exists without an index.php, so
// the short form returns a hard 404. See adaptive-release.spec.ts.
// `_limit`, NOT `limit`. OpenRegister control parameters carry a leading
// underscore, and an UNRECOGNISED parameter is not ignored — it is applied as a
// PROPERTY FILTER. So `?limit=200` asks for Courses whose `limit` property
// equals 200, no object has one, and the endpoint answers HTTP 200 with a
// well-formed `{"results":[],"total":0}`.
//
// Measured live 2026-08-24 against a seeded instance:
//   ?_limit=200      -> total=3
//   ?limit=200       -> total=0
//   ?bogusprop=xyz   -> total=0     (same shape — it really is a filter)
//
// `resp.ok()` is therefore TRUE, the guard below reads an empty list, and the
// test skips as "No top-level Course seeded" — blaming the fixture for a typo
// in the query. This spec ran 0 of its 4 tests on every green CI run.
const COURSE_LIST_API =
	'/index.php/apps/openregister/api/objects/learniq/Course?_limit=200'

/**
 * Fetch every Course and return the first top-level one (no parentCourseId),
 * or null when none exists in this environment.
 *
 * @param page The Playwright page (used for its authenticated request context).
 */
async function findTopLevelCourse(page: Page) {
	const resp = await page.request.get(COURSE_LIST_API, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const courses = json.results ?? json.objects ?? json ?? []
	return courses.find((c: any) => !c.parentCourseId) ?? null
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

async function openCourseBuilder(page: Page, courseId: string) {
	// ⚠️ NO `#` — the router is HISTORY mode, not hash mode.
	//
	// src/main.js builds it with `createWebHistory(generateUrl('/apps/learniq'))`.
	// vue-router strips that base from `location.pathname` and appends the
	// UNTOUCHED hash, so `/index.php/apps/learniq/courses/<id>/builder`
	// resolved to `/#/courses/<id>/builder`, matched no declared route, and fell
	// through `routesFromManifest`'s `/:pathMatch(.*)*` catch-all — which
	// `redirect: '/'`s to the DASHBOARD. Every failure screenshot in CI run
	// 32833668787 shows "Administrator · Dashboard", not the builder, which is
	// why `.course-builder__header` never appeared.
	//
	// This spec did not regress: it never ran. Its `?limit=200` fixture probe
	// (see COURSE_LIST_API above) returned an empty list, so all four tests
	// skipped on every green run until that typo was fixed in #597 — and the
	// first run that actually executed them hit this second bug. Same plain-path
	// form as detail-pages.spec.ts / accessibility-conformance.spec.ts, which
	// both carry the same warning.
	await page.goto(`/index.php/apps/learniq/courses/${courseId}/builder`)

	// The catch-all redirect rewrites the URL when no route matched, so this
	// separates "the builder is slow/broken" from "we are not on the builder
	// at all" — the latter is what a 20s selector timeout used to look like.
	// Matched on the `/builder` leaf rather than the whole route so an id
	// canonicalised in the URL does not read as a routing failure; the
	// dashboard's pathname is the bare app base and has no such segment.
	await expect
		.poll(() => new URL(page.url()).pathname, {
			message: 'router fell through to the dashboard — no route matched',
			timeout: 10_000,
		})
		.toMatch(/\/courses\/[^/]+\/builder\/?$/)

	// Wait for the BUILDER, not for the document.
	//
	// `domcontentloaded` fires as soon as the shell parses, long before Vue has
	// mounted the view or fetched the course. Waiting on `body` is weaker still
	// — `body` exists immediately. The old helper did both and then the test
	// read `innerText('body')` ONCE, with no retry, so it asserted against
	// whatever had rendered by that instant: the app chrome and nothing else.
	//
	// `.course-builder__header` is the right anchor because CourseBuilder.vue
	// renders exactly one of three branches — `loading`, `error`, or the
	// content — and the header exists only in the third. Reaching it therefore
	// proves the course resolved, which is what every assertion below assumes.
	//
	// It is also translation-independent: every string in this view goes
	// through `t('learniq', ...)`, so a class is a stable anchor where the
	// heading text is not.
	await page.waitForSelector('.course-builder__header', { timeout: 20_000 })
}

test.describe('course-authoring-ux — CourseBuilder / LessonComposer / LessonPlayer', () => {
	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-designer-sets-module-order-in-the-course-builder
	test('course-builder-add-and-reorder-modules', async ({
		loggedInPage: page,
	}) => {
		const course = await findTopLevelCourse(page)
		requireFixture(course, 'a top-level Course')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		// Structural, not textual — see openCourseBuilder(). The heading itself
		// is translated, so assert the surface that is not.
		await expect(page.locator('.course-builder__header')).toBeVisible()

		// Add two modules through the builder's own UI (no raw API POST).
		const moduleNameInput = page.getByPlaceholder('New module name')
		await moduleNameInput.fill('e2e Module A')
		await page.getByRole('button', { name: 'Add module' }).click()
		await moduleNameInput.fill('e2e Module B')
		await page.getByRole('button', { name: 'Add module' }).click()

		await expect(page.getByText('e2e Module A')).toBeVisible()
		await expect(page.getByText('e2e Module B')).toBeVisible()

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-lessons-within-a-course-by-drag-and-drop
	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-lessons-within-a-course-using-only-the-keyboard
	test('course-builder-reorders-lessons-by-drag-and-drop-and-by-keyboard', async ({
		loggedInPage: page,
	}) => {
		const course = await findTopLevelCourse(page)
		requireFixture(course, 'a top-level Course')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		const moduleNameInput = page.getByPlaceholder('New module name')
		await moduleNameInput.fill('e2e Reorder Module')
		await page.getByRole('button', { name: 'Add module' }).click()

		const moduleRow = page.locator('.course-builder__module', {
			hasText: 'e2e Reorder Module',
		})
		const lessonNameInput = moduleRow.getByPlaceholder('New lesson name')
		const lessonRows = moduleRow.locator('.course-builder__lesson')

		// Wait for each lesson to LAND before adding the next.
		//
		// addLesson() computes `order: module.lessons.length + 1` and appends
		// on resolve, so firing the second click while the first create is
		// still in flight gives BOTH lessons `order: 1` and leaves the rendered
		// order down to which POST returns first. Measured on CI run
		// 32845673708: the list came back as [Lesson 2, Lesson 1], so
		// "Move lesson 'e2e Lesson 2' up" was disabled — it was already first —
		// and the click timed out after 40s against a permanently disabled
		// button.
		//
		// Waiting on the row is also what a user actually does, and it keeps
		// the reorder assertions below testing reordering rather than a race.
		await lessonNameInput.fill('e2e Lesson 1')
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()
		await expect(lessonRows).toHaveCount(1)

		await lessonNameInput.fill('e2e Lesson 2')
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()
		await expect(lessonRows).toHaveCount(2)

		// The fixture the reorder assertions assume: creation order, so
		// "move Lesson 2 up" is a real move rather than a no-op on row 0.
		await expect(lessonRows.first()).toContainText('e2e Lesson 1')

		// Keyboard reorder: move the second lesson up via its "Move ... up" button.
		await moduleRow
			.getByRole('button', { name: /Move lesson 'e2e Lesson 2' up/ })
			.click()
		await expect(lessonRows.first()).toContainText('e2e Lesson 2')

		// Drag-and-drop reorder: drag the (now first) lesson's handle onto the
		// second row — exercises vuedraggable/SortableJS's pointer-driven drag.
		const firstHandle = lessonRows.nth(0).locator('.course-builder__handle')
		const secondRow = lessonRows.nth(1)
		await firstHandle.dragTo(secondRow)

		// One of the two lessons is now first — assert the drag mutated order
		// (both permutations are valid outcomes of a single swap; what matters
		// is the DnD interaction was accepted and order round-tripped, not a
		// specific final sequence, since SortableJS's exact drop index depends
		// on pointer geometry this harness doesn't control precisely).
		await expect(lessonRows).toHaveCount(2)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-an-instructional-designer-composes-a-lesson-from-mixed-blocks
	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-teacher-reorders-blocks-within-a-lesson-using-only-the-keyboard
	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-a-learner-opens-a-native-lesson-and-sees-its-composed-blocks-in-order
	test('lesson-composer-adds-blocks-reorders-by-keyboard-and-lesson-player-renders-them', async ({
		loggedInPage: page,
	}) => {
		const course = await findTopLevelCourse(page)
		requireFixture(course, 'a top-level Course')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		const moduleNameInput = page.getByPlaceholder('New module name')
		await moduleNameInput.fill('e2e Compose Module')
		await page.getByRole('button', { name: 'Add module' }).click()

		const moduleRow = page.locator('.course-builder__module', {
			hasText: 'e2e Compose Module',
		})
		const lessonNameInput = moduleRow.getByPlaceholder('New lesson name')
		await lessonNameInput.fill('e2e Compose Lesson')
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()

		// `exact: true` is load-bearing. getByRole's `name` is a SUBSTRING match
		// by default, and this module is called "e2e Compose Module", so its
		// three icon buttons carry accessible names containing "Compose"
		// ("Move module 'e2e Compose Module' up", … down, "Delete module …").
		// Without it the locator resolved to 4 elements and Playwright failed
		// on strict mode rather than clicking the lesson's Compose button.
		await moduleRow.getByRole('button', { name: 'Compose', exact: true }).click()
		// No page load here — Compose is an in-app view switch, so waiting on
		// `body` / `domcontentloaded` measured nothing and only read as a wait.
		// The retrying assertion below is what actually waits for the view.
		await expect(page.getByText('Compose lesson')).toBeVisible({
			timeout: 20_000,
		})

		// Add two richText blocks (no file-picker/NcSelect data dependency —
		// drivable without seeded Material/Assessment fixtures).
		await page.getByRole('button', { name: 'Add block' }).click()
		await page.getByRole('button', { name: 'Add block' }).click()
		const blockRows = page.locator('.lesson-composer__block')
		await expect(blockRows).toHaveCount(2)

		const textareas = page.locator('[data-testid="cn-markdown-textarea"]')
		await textareas.nth(0).fill('First block text')
		await textareas.nth(1).fill('Second block text')

		// Keyboard-only block reorder: move the SECOND block up.
		//
		// Addressed by position, which is now part of the button's accessible
		// name. It previously read `/Move Rich text block up/`, which matched
		// one control per block — two rich-text blocks, two identical names,
		// strict-mode violation. That ambiguity was a real a11y defect rather
		// than a test problem (a screen-reader user heard the same name twice
		// with no way to tell the controls apart), so it is fixed in
		// LessonComposer.vue and the locator simply names the block it means.
		await page.getByRole('button', { name: 'Move Rich text block 2 up' }).click()
		await expect(textareas.nth(0)).toHaveValue('Second block text')

		await page.getByRole('button', { name: 'Save lesson' }).click()
		await expect(page.getByText('Lesson saved.')).toBeVisible()

		// LessonPlayer renders the persisted, reordered blocks.
		await page.getByRole('button', { name: 'Preview' }).click()
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('domcontentloaded')

		const rendered = page.locator('.lesson-player__block-richtext')
		await expect(rendered).toHaveCount(2)
		await expect(rendered.nth(0)).toContainText('Second block text')
		await expect(rendered.nth(1)).toContainText('First block text')

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})

	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-an-instructional-designer-saves-a-published-course-as-a-template
	// @e2e openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-instantiating-a-template-creates-a-fresh-independent-course-tree
	test('save-course-as-template-and-instantiate-a-new-course-from-it', async ({
		loggedInPage: page,
	}) => {
		const course = await findTopLevelCourse(page)
		requireFixture(course, 'a top-level Course')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		await page.getByRole('button', { name: 'Save as template' }).click()
		const templateName = `e2e Template ${Date.now()}`
		await page.locator('#cb-template-name').fill(templateName)
		await page.getByRole('button', { name: 'Save template' }).click()

		// Assert the OUTCOME, not the confirmation banner.
		//
		// This used to wait for "Template saved.". That string is unobservable:
		// saveAsTemplate() sets `saveTemplateDone` and then, in the same
		// handler, `$router.push`es to CourseTemplateDetail — so the banner is
		// replaced by the destination route within a tick. Measured on CI run
		// 32842613952 once the schema-slug 404 was fixed: the create answered
		// **201 Created** and the trace shows the app immediately GETting
		// `/objects/learniq/course-template/<uuid>` for the detail page, while
		// the assertion timed out after 15s having never seen the text.
		//
		// Landing on the new template's own detail route is the stronger
		// assertion anyway: it can only happen if the create succeeded AND
		// returned an id. A save that 404s (the original defect) fails it.
		await page.waitForURL(/\/courses\/templates\/[0-9a-f-]+$/, {
			timeout: 15_000,
		})

		// Instantiate-from-template: back on a fresh CourseBuilder (any course,
		// the action creates a brand-new independent Course tree regardless of
		// the current context course).
		await openCourseBuilder(page, course.id ?? course.uuid)
		await page.getByRole('button', { name: 'New course from template' }).click()
		const newCourseName = `e2e New Course ${Date.now()}`
		await page.locator('#cb-new-course-name').fill(newCourseName)

		// Select the just-saved template via NcSelect.
		//
		// ⚠️ NOT `getByText('Template').first()`. getByText is a
		// case-insensitive SUBSTRING match, and the app's own sidebar carries a
		// "Course templates" link — which is earlier in the DOM than this
		// panel, so `.first()` clicked the NAVIGATION and left the page.
		// Measured on CI run 32845673708, the frame URL went
		//   /courses/<id>/builder -> /courses/templates -> /courses/templates/<id>
		// so the following `getByText(templateName)` then clicked that
		// template's row in the index, and "Create course" — which is disabled
		// until `instantiateForm.templateId` is set — was never reachable. The
		// 40s timeout was on a control that had never become enabled, on a page
		// the test had navigated away from.
		//
		// Scope to the panel and use the combobox/option roles, the pattern
		// nextcloud-app.spec.ts already uses for NcSelect.
		const instantiatePanel = page.locator('.course-builder__panel', {
			hasText: 'Create a new course from a template',
		})
		// The panel holds exactly one combobox (the template NcSelect); its
		// siblings are a textbox and a button. Scoping rather than naming keeps
		// this off NcSelect's internal label wiring, and strict mode still fails
		// loudly if a second combobox ever appears here.
		await instantiatePanel.getByRole('combobox').click()
		// The dropdown renders in a portal outside the panel, so match at page
		// level — on the template this test just created, by its unique name.
		//
		// Filtered by TEXT CONTENT, not by accessible name. NcSelect renders an
		// option's label across several elements, and accessible-name
		// computation joins those with a space: the snapshot for this very
		// template read `option "e2e Template 178 7662941039"` — a space the
		// name never contained — so `{ name: templateName }` could not match.
		// `hasText` reads textContent, which concatenates without the space.
		await page.getByRole('option').filter({ hasText: templateName }).click()
		await page.getByRole('button', { name: 'Create course' }).click()

		// Instantiation navigates to the new Course's own builder — proves a
		// fresh, independent Course (with a different :courseId) was created,
		// starting lifecycle draft with zero enrolments.
		await page.waitForURL(/\/courses\/[0-9a-f-]+\/builder/, { timeout: 15_000 })
		const bodyText = await page.innerText('body')
		expect(bodyText).toContain('Course builder')

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(
			0,
		)
	})
})
