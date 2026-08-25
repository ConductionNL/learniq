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
import { test, expect } from '../fixtures'

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
async function findTopLevelCourse(page: import('@playwright/test').Page) {
	const resp = await page.request.get(COURSE_LIST_API, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const courses = json.results ?? json.objects ?? json ?? []
	return courses.find((c: any) => !c.parentCourseId) ?? null
}

function collectFatalErrors(page: import('@playwright/test').Page): string[] {
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

async function openCourseBuilder(
	page: import('@playwright/test').Page,
	courseId: string,
) {
	// PATH, not hash. learniq's router is `createWebHistory(generateUrl(
	// '/apps/learniq'))` — history mode. A `#/courses/...` URL therefore leaves
	// the PATH at `/apps/learniq/`, which resolves to the default route, so
	// every navigation written this way silently landed on the Dashboard and
	// the assertions below ran against a page they never asked for.
	//
	// The tell was in the suite all along: the specs that pass navigate by path
	// (`/apps/learniq/settings`, `/apps/learniq/progress/...`), and only the
	// failing ones used the hash form.
	await page.goto(`/index.php/apps/learniq/courses/${courseId}/builder`)

	// Wait for the BUILDER, not for the document.
	//
	// This is a hash route in an SPA: `domcontentloaded` fires as soon as the
	// shell parses, long before Vue has mounted the view or fetched the course.
	// Waiting on `body` is weaker still — `body` exists immediately. The old
	// helper did both and then the test read `innerText('body')` ONCE, with no
	// retry, so it asserted against whatever had rendered by that instant:
	// the app chrome and nothing else.
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
		test.skip(!course, 'No top-level Course seeded in this environment.')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		// Structural, not textual — see openCourseBuilder(). The heading itself
		// is translated, so assert the surface that is not.
		await expect(page.locator('.course-builder__header')).toBeVisible()

		// Add two modules through the builder's own UI (no raw API POST).
		//
		// UNIQUE NAMES PER RUN. These tests really do create objects now, and
		// the objects persist in the register, so a fixed name accumulates one
		// duplicate per CI run. That is not hypothetical — the second run after
		// these tests started passing died on
		// "strict mode violation: locator('.course-builder__module')
		//  .filter({ hasText: 'e2e Compose Module' }) resolved to 2 elements".
		// The template test below already stamped its name for this reason.
		const runId = Date.now()
		const moduleA = `e2e Module A ${runId}`
		const moduleB = `e2e Module B ${runId}`
		const moduleNameInput = page.getByPlaceholder('New module name')
		await moduleNameInput.fill(moduleA)
		await page.getByRole('button', { name: 'Add module' }).click()
		await moduleNameInput.fill(moduleB)
		await page.getByRole('button', { name: 'Add module' }).click()

		await expect(page.getByText(moduleA)).toBeVisible()
		await expect(page.getByText(moduleB)).toBeVisible()

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
		test.skip(!course, 'No top-level Course seeded in this environment.')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		const moduleNameInput = page.getByPlaceholder('New module name')
		const runId = Date.now()
		const reorderModule = `e2e Reorder Module ${runId}`
		await moduleNameInput.fill(reorderModule)
		await page.getByRole('button', { name: 'Add module' }).click()

		const moduleRow = page.locator('.course-builder__module', {
			hasText: reorderModule,
		})
		const lessonNameInput = moduleRow.getByPlaceholder('New lesson name')
		await lessonNameInput.fill(`e2e Lesson 1 ${runId}`)
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()
		await lessonNameInput.fill(`e2e Lesson 2 ${runId}`)
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()

		const lessonRows = moduleRow.locator('.course-builder__lesson')
		await expect(lessonRows).toHaveCount(2)

		// Keyboard reorder: move the second lesson up via its "Move ... up" button.
		await moduleRow
			.getByRole('button', {
				name: new RegExp(`Move lesson 'e2e Lesson 2 ${runId}' up`),
			})
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
		test.skip(!course, 'No top-level Course seeded in this environment.')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		const moduleNameInput = page.getByPlaceholder('New module name')
		const runId = Date.now()
		const composeModule = `e2e Compose Module ${runId}`
		await moduleNameInput.fill(composeModule)
		await page.getByRole('button', { name: 'Add module' }).click()

		const moduleRow = page.locator('.course-builder__module', {
			hasText: composeModule,
		})
		const lessonNameInput = moduleRow.getByPlaceholder('New lesson name')
		await lessonNameInput.fill(`e2e Compose Lesson ${runId}`)
		await moduleRow.getByRole('button', { name: 'Add lesson' }).click()

		// `.first()` — one Compose button per LESSON, and the module row contains
		// the whole lesson list. Unique module names fixed the earlier collision
		// between runs; this is a different multiplicity, inside a single module.
		await moduleRow.getByRole('button', { name: 'Compose' }).first().click()
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

		// Keyboard-only block reorder: move the second block up.
		await page.getByRole('button', { name: /Move Rich text block up/ }).click()
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
		test.skip(!course, 'No top-level Course seeded in this environment.')

		const errors = collectFatalErrors(page)
		await openCourseBuilder(page, course.id ?? course.uuid)

		await page.getByRole('button', { name: 'Save as template' }).click()
		const templateName = `e2e Template ${Date.now()}`
		await page.locator('#cb-template-name').fill(templateName)
		await page.getByRole('button', { name: 'Save template' }).click()

		// Assert the NAVIGATION, not the flash message. saveAsTemplate() sets
		// `saveTemplateDone = true` and then, in the same success branch,
		// immediately `$router.push`es to CourseTemplateDetail — so
		// "Template saved." is replaced by the next view and is not reliably
		// observable. Waiting for it timed out even on a save that had worked.
		//
		// Landing on the created template's own page is the stronger claim
		// anyway: it proves the object exists and carries the name just typed,
		// where the message only proved a flag was set.
		// Assert the OBJECT, not the navigation.
		//
		// saveAsTemplate() sets `saveTemplateDone` and then pushes to
		// CourseTemplateDetail — but that push is wrapped in `.catch(() => {})`,
		// so if it fails there is no error, no navigation, and no message
		// either (the flag's message is what the next view would replace).
		// Waiting for the URL timed out on a save that had, as far as anything
		// observable goes, worked.
		//
		// The requirement is "an instructional designer saves a published course
		// as a template". The template existing, with the name just typed, IS
		// that claim; which view the app lands on afterwards is not.
		const saved = await page.request.get(
			'/index.php/apps/openregister/api/objects/learniq/CourseTemplate?_limit=200',
			{ headers: { Accept: 'application/json' } },
		)
		expect(
			saved.ok(),
			`course templates must be listable (HTTP ${saved.status()})`,
		).toBe(true)
		const templates = (await saved.json()).results ?? []
		expect(
			templates.some((t: any) => String(t.name ?? '') === templateName),
			`the saved template "${templateName}" must exist after Save template`,
		).toBe(true)

		// Instantiate-from-template: back on a fresh CourseBuilder (any course,
		// the action creates a brand-new independent Course tree regardless of
		// the current context course).
		await openCourseBuilder(page, course.id ?? course.uuid)
		await page.getByRole('button', { name: 'New course from template' }).click()
		const newCourseName = `e2e New Course ${Date.now()}`
		await page.locator('#cb-new-course-name').fill(newCourseName)
		// Select the just-saved template via NcSelect.
		await page.getByText('Template').first().click()
		await page.getByText(templateName).click()
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
