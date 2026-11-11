/**
 * The sentences the export page shows for a publish outcome and for a
 * sharing-gate reason, in the user's language.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
 * @spec openspec/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The sentence for a store that could not take the course.
 *
 * @return {string} The sentence.
 */
function storeCouldNotTake() {
	return t(
		'learniq',
		'The course marketplace could not take the course. Try again later.',
	)
}

/** Publish outcomes other than ok, each with its sentence. */
const PUBLISH_TEXTS = new Map([
	['not_configured', () => t(
		'learniq',
		'No course marketplace is set up yet. Ask your administrator.',
	)],
	['too_large', () => t(
		'learniq',
		'This course is too large for the marketplace.',
	)],
	['store_unreachable', storeCouldNotTake],
	['store_rejected', storeCouldNotTake],
	['store_invalid_response', storeCouldNotTake],
	['rate_limited', () => t(
		'learniq',
		'The course marketplace is busy. Try again in a few minutes.',
	)],
	['forbidden', () => t(
		'learniq',
		'You may not publish courses to the marketplace. Your administrator decides who may.',
	)],
	['publish_not_supported', () => t(
		'learniq',
		'This server cannot publish to a course marketplace yet. Ask your administrator to update OpenRegister.',
	)],
])

/**
 * A publish outcome other than ok, as a sentence.
 *
 * @param {string} outcome The server's outcome.
 * @return {string} The sentence.
 */
export function publishText(outcome) {
	const text = PUBLISH_TEXTS.get(outcome)
	return text ? text() : t('learniq', 'The course could not be published.')
}

/**
 * The sentence for a lesson whose licence does not allow sharing.
 *
 * @param {string} name The lesson's name.
 * @return {string} The sentence.
 */
function lessonNotOpen(name) {
	return t(
		'learniq',
		'Lesson {name} has a licence that does not allow sharing.',
		{ name },
	)
}

/**
 * The sentence for a material whose licence does not allow sharing.
 *
 * @param {string} name The material's name.
 * @return {string} The sentence.
 */
function materialNotOpen(name) {
	return t(
		'learniq',
		'Material {name} has a licence that does not allow sharing.',
		{ name },
	)
}

/** Sharing-gate reason codes, each with its sentence (given the named item). */
const BLOCKER_TEXTS = new Map([
	['licence-missing', () => t('learniq', 'Set a licence on the course first.')],
	['licence-not-open', () => t(
		'learniq',
		'The course licence does not allow sharing. Choose an open licence.',
	)],
	['author-missing', () => t(
		'learniq',
		'Name the author on the course, so others can credit them.',
	)],
	['lesson-licence-not-open', lessonNotOpen],
	['material-licence-not-open', materialNotOpen],
	['pupil-data-not-confirmed', () => t(
		'learniq',
		'Confirm that the package holds no pupil data.',
	)],
	['rights-not-confirmed', () => t(
		'learniq',
		'Confirm that the school may share everything in it.',
	)],
])

/**
 * One sharing-gate reason as a sentence; an unknown reason shows its code.
 *
 * @param {{code: string, name: string}} blocker The reason.
 * @return {string} The sentence.
 */
export function blockerText(blocker) {
	const text = BLOCKER_TEXTS.get(blocker?.code)
	return text ? text(blocker?.name ?? '') : blocker?.code ?? ''
}
