// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for the lesson onboarding review page
 * (office-file-lesson-onboarding). The page lists the teacher's detected
 * Word and PowerPoint files straight from OpenRegister, dismisses them there,
 * and asks learniq to import one confirmed file (decision D17). These helpers
 * build those requests and read the answers, so they are tested under
 * `node --test` without an SFC compile step. URLs are app-relative; the page
 * passes them through `generateUrl`.
 *
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */

/** OpenRegister's object API for the detection rows. */
export const ROWS_URL =
	'/apps/openregister/api/objects/learniq/lesson-onboarding-file'

/** learniq's onboarding API. */
export const ONBOARDING_API = '/apps/learniq/api/lesson-onboarding'

/**
 * The list URL for one teacher's rows in one state, newest first.
 *
 * @param {string} userId The signed-in teacher.
 * @param {string} lifecycle `detected` or `imported`.
 * @param {number} [limit] How many rows.
 * @return {string} The app-relative URL.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */
export function rowsUrl(userId, lifecycle, limit = 100) {
	const query = new URLSearchParams({
		teacherId: String(userId ?? ''),
		lifecycle,
		_limit: String(limit),
		'_order[detectedAt]': 'desc',
	})
	return `${ROWS_URL}?${query.toString()}`
}

/**
 * The PATCH that dismisses a row: only the lifecycle, which OpenRegister
 * resolves to the `dismiss` transition. Nothing is read from the file.
 *
 * @param {string} rowId The row uuid.
 * @return {{url: string, body: object}} The request.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-dismisses-a-file
 */
export function dismissRequest(rowId) {
	return {
		url: `${ROWS_URL}/${encodeURIComponent(rowId)}`,
		body: { lifecycle: 'dismissed' },
	}
}

/**
 * The POST that imports one confirmed row into a course.
 *
 * @param {string} rowId The row uuid.
 * @param {string} courseId The chosen course.
 * @return {{url: string, body: object}} The request.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */
export function importRequest(rowId, courseId) {
	return {
		url: `${ONBOARDING_API}/files/${encodeURIComponent(rowId)}/import`,
		body: { courseId: String(courseId ?? '') },
	}
}

/**
 * Rows from an OpenRegister list answer, whatever envelope it uses.
 *
 * @param {object|Array} json The answer.
 * @return {Array<object>} The rows.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */
export function rowsFrom(json) {
	if (Array.isArray(json)) return json
	return json?.results ?? json?.objects ?? []
}

/**
 * The key of the message the page shows for a refused import, from the
 * status and the `reason` in learniq's answer. The page translates the key.
 *
 * @param {number} status The HTTP status.
 * @param {object|null} body The answer body.
 * @return {string} One of the message keys below.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-openregister-has-no-presentation-reader-yet
 */
export function importErrorKey(status, body) {
	const reason = body?.reason ?? ''
	if (status === 503 || reason === 'reader-unavailable')
		return 'reader-unavailable'
	if (status === 410) return 'file-gone'
	if (status === 409) return 'not-detected'
	if (status === 404) return 'not-found'
	if (reason === 'course-not-found') return 'course-not-found'
	if (status === 422) return 'unreadable'
	if (status === 400) return 'no-course'
	return 'failed'
}

/**
 * A label for the row's format.
 *
 * @param {string} format `docx` or `pptx`.
 * @return {string} `Word` or `PowerPoint`, or the raw value.
 * @spec exclude Presentation-only label for the two fixed format values.
 */
export function formatLabel(format) {
	if (format === 'docx') return 'Word'
	if (format === 'pptx') return 'PowerPoint'
	return String(format ?? '')
}
