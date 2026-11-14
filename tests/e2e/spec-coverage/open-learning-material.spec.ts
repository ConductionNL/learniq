/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — open-learning-material spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
 *   @e2e openspec/specs/open-learning-material/spec.md#scenario-options-stay-visible-while-a-filter-is-on
 *   @e2e openspec/specs/open-learning-material/spec.md#scenario-edurep-does-not-answer
 *
 * The source is integriq's Edurep adapter, which this suite never calls: the
 * search and add answers of learniq's own API are recorded responses served
 * through page.route, so the scenarios test the panel and not Kennisnet. The
 * licence rules behind the add are covered by PHPUnit
 * (MaterialLicenceRulesTest, OpenMaterialImportServiceTest).
 *
 * A Lesson with a course is discovered through the OpenRegister object API;
 * the scenario is skipped (not failed) only on an unseeded instance.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '../fixtures.ts'
import { requireFixture } from '../seeded.ts'

const LESSON_LIST_API = '/index.php/apps/openregister/api/objects/learniq/lesson?_limit=50'

const WIKIWIJS = {
	source: 'edurep',
	sourceId: 'urn:wikiwijsmaken:194413',
	title: 'Het schrijven van een betoog',
	description: 'Studenten schrijven een betoog over een zelf gekozen onderwerp.',
	url: 'https://maken.wikiwijs.nl/194413/Het_schrijven_van_een_betoog',
	files: [],
	authors: ['Kirstin Beekman'],
	license: 'CC-BY-4.0',
	accessRights: 'OpenAccess',
	levels: ['BVE'],
	materialType: 'application/x-Wikiwijs-Arrangement',
	collection: 'wikiwijsmaken',
	published: '2023-01-29',
	decision: { mode: 'link', reason: 'no-copyable-file', nonCommercial: false },
}

/**
 * The first Lesson that belongs to a course, or null.
 *
 * @param page The logged-in page.
 * @return The lesson's id and course id, or null.
 */
async function findLesson(page: Page): Promise<{ id: string, courseId: string } | null> {
	const resp = await page.request.get(LESSON_LIST_API)
	if (!resp.ok()) {
		return null
	}
	const body = await resp.json()
	const lesson = (body.results ?? []).find((row: { courseId?: string }) => row.courseId)
	return lesson ? { id: lesson.id ?? lesson.uuid, courseId: lesson.courseId } : null
}

/**
 * Serve the panel settings with open material switched on.
 *
 * @param page The page.
 * @return {Promise<void>}
 */
async function servePanel(page: Page): Promise<void> {
	await page.route('**/apps/learniq/api/open-material/panel**', (route) => route.fulfill({
		json: { enabled: true, available: true, defaultLevels: ['VO'], collectionsOff: ['KlasCement'], linkCollections: ['Schooltv'] },
	}))
}

/**
 * Open the lesson editor and the search panel from its first media block.
 *
 * @param page The page.
 * @param lesson The lesson.
 * @return {Promise<void>}
 */
async function openSearch(page: Page, lesson: { id: string, courseId: string }): Promise<void> {
	// No `#`: the router is history mode (see course-authoring-ux.spec.ts).
	await page.goto(`/index.php/apps/learniq/courses/${lesson.courseId}/lessons/${lesson.id}/compose`)
	await expect(page.getByText('Compose lesson')).toBeVisible({ timeout: 20_000 })
	const palette = page.getByTestId('cn-block-list-palette')
	await palette.getByRole('combobox').click()
	await page.getByRole('option', { name: 'Media', exact: true }).click()
	await palette.getByRole('button', { name: 'Add block' }).click()
	await page.getByTestId('open-material-open').first().click()
	await expect(page.getByTestId('open-material-search')).toBeVisible()
}

test.describe('open-learning-material — the search panel in the lesson editor', () => {
	test('a teacher searches and adds a CC BY result as a link', async ({ loggedInPage: page }) => {
		const lesson = await findLesson(page)
		requireFixture(lesson, 'a Lesson that belongs to a course')
		await servePanel(page)
		let searched = ''
		await page.route('**/apps/learniq/api/open-material/search**', (route) => {
			searched = new URL(route.request().url()).searchParams.get('query') ?? ''
			return route.fulfill({ json: { total: 1, page: 1, pageSize: 10, records: [WIKIWIJS], facets: { 'meta.repository.id': [{ value: 'wikiwijsmaken', count: 35 }, { value: 'KlasCement', count: 32 }] } } })
		})
		await page.route('**/apps/learniq/api/open-material/materials', (route) => route.fulfill({
			status: 201,
			json: { id: 'e2e-material-1', title: WIKIWIJS.title, kind: 'link', source: 'edurep', sourceId: WIKIWIJS.sourceId, sourceUrl: WIKIWIJS.url, sourceCollection: 'wikiwijsmaken', authors: WIKIWIJS.authors, license: 'CC-BY-4.0' },
		}))

		await openSearch(page, lesson as { id: string, courseId: string })
		await page.getByTestId('open-material-query').locator('input').fill('betoog')
		await page.getByRole('button', { name: 'Search', exact: true }).click()

		await expect(page.getByTestId('open-material-result')).toHaveCount(1)
		expect(searched).toBe('betoog')
		await expect(page.getByTestId('open-material-result')).toContainText('CC BY 4.0')
		await page.getByTestId('open-material-add').click()
		await expect(page.getByTestId('open-material-search')).toBeHidden()
		await expect(page.getByTestId('material-attribution').first()).toContainText('Kirstin Beekman')
	})

	test('options stay visible and can be ticked again while a filter is on', async ({ loggedInPage: page }) => {
		const lesson = await findLesson(page)
		requireFixture(lesson, 'a Lesson that belongs to a course')
		await servePanel(page)
		const asked: URLSearchParams[] = []
		await page.route('**/apps/learniq/api/open-material/search**', (route) => {
			asked.push(new URL(route.request().url()).searchParams)
			return route.fulfill({
				json: {
					total: 1,
					page: 1,
					pageSize: 10,
					records: [WIKIWIJS],
					facets: {
						'lom.educational.context': [{ value: 'VO', count: 13 }, { value: 'WO', count: 15 }],
						'lom.rights.copyrightandotherrestrictions': [{ value: 'cc-by-40', count: 36 }],
						'meta.repository.id': [{ value: 'wikiwijsmaken', count: 35 }, { value: 'KlasCement', count: 25 }],
					},
				},
			})
		})

		await openSearch(page, lesson as { id: string, courseId: string })
		await page.getByTestId('open-material-query').locator('input').fill('betoog')
		await page.getByRole('button', { name: 'Search', exact: true }).click()

		const filters = page.getByRole('complementary', { name: 'Filters' })
		await expect(filters.getByText('KlasCement (Flanders)')).toBeVisible()
		await filters.getByText('CC BY 4.0').click()
		await expect(filters.getByText('CC BY 4.0')).toBeVisible()
		await expect.poll(() => asked.at(-1)?.getAll('excludeLicenses[]')).toEqual(['CC-BY-4.0'])
		await filters.getByText('CC BY 4.0').click()
		await expect.poll(() => asked.at(-1)?.getAll('excludeLicenses[]')).toEqual([])
	})

	test('when Edurep does not answer, the panel says so and offers a retry', async ({ loggedInPage: page }) => {
		const lesson = await findLesson(page)
		requireFixture(lesson, 'a Lesson that belongs to a course')
		await servePanel(page)
		await page.route('**/apps/learniq/api/open-material/search**', (route) => route.fulfill({ status: 503, json: { error: 'Edurep did not answer within 10 seconds. (source-unavailable)' } }))

		await openSearch(page, lesson as { id: string, courseId: string })
		await page.getByTestId('open-material-query').locator('input').fill('betoog')
		await page.getByRole('button', { name: 'Search', exact: true }).click()

		const state = page.getByTestId('open-material-state')
		await expect(state).toContainText('Edurep does not answer')
		await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Search your own files' })).toBeVisible()
	})
})
