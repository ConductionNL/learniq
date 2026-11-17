// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// training-provider-course-days T4: the course days board. A drag on the
// board moves a day by hand through the server's move (the status is derived;
// a manual move is logged and lasts until the next change), never by writing
// `dayStatus` on the row. The handler is resolved by NAME from the page's
// `board.moveHandler`.
//
// @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself

/**
 * The URL the move posts to.
 *
 * @param {string} id The course day (cohort) id.
 * @return {string} The app-relative path.
 * @spec openspec/changes/training-provider-course-days/tasks.md
 */
export function courseDayStatusPath(id) {
	return '/apps/learniq/api/course-days/' + encodeURIComponent(String(id)) + '/status'
}

/**
 * Build the board's move handler.
 *
 * @param {{post: function(string, object): Promise<{data: object}>, generateUrl: function(string): string}} deps Sends the request and builds the instance URL.
 * @return {{moveCourseDay: function({card: object, toKey: string}): Promise<object>}} The handler, keyed by its manifest name.
 * @spec openspec/changes/training-provider-course-days/tasks.md
 */
export function createCourseDayHandlers({ post, generateUrl }) {
	return {
		/**
		 * Move a course day to the column it was dropped on.
		 *
		 * @param {{card: object, toKey: string}} move The drop.
		 * @return {Promise<object>} The card with its new status.
		 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
		 */
		async moveCourseDay({ card, toKey }) {
			const id = card?.id ?? card?.['@self']?.id
			if (!id) {
				throw new Error('This course day cannot be moved.')
			}
			try {
				const response = await post(generateUrl(courseDayStatusPath(id)), { status: String(toKey) })
				return { ...card, dayStatus: response?.data?.dayStatus ?? String(toKey) }
			} catch (error) {
				throw new Error(String(error?.response?.data?.error || error?.response?.data?.message || 'The course day could not be moved.'), { cause: error })
			}
		},
	}
}
