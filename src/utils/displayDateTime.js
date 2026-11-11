// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A date and time as the review queues show it (the timetable conflict
 * queue and the proctoring review queue): in the reader's language, nothing
 * for no date, and the raw value when it is not a date.
 *
 * @param {string} dt ISO datetime string.
 * @return {string}
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
 */
export function displayDateTime(dt) {
	if (!dt) return ''
	try {
		return new Intl.DateTimeFormat(navigator.language, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		}).format(new Date(dt))
	} catch {
		return dt
	}
}
