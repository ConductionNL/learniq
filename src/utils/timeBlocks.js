// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The `timeBlocks` list column formatter: a teacher availability's free time
 * blocks as a teacher reads them, "do 15 okt, 18:00–20:00", in the reader's
 * language and time zone, several blocks separated by "; ". A block that ends
 * on another day names that day too. Without this the list showed the stored
 * JSON (teacher-availability-reads-words).
 *
 * Pure: the locale and time zone are passed in, so the module runs under
 * `node --test` with nothing mocked.
 *
 * @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md#requirement-the-teacher-availability-list-reads-words
 */

/**
 * A date-time string as a Date, or null when it is not one.
 *
 * @param {unknown} value The stored value.
 * @return {Date|null}
 */
function toDate(value) {
	if (typeof value !== 'string' || value === '') {
		return null
	}
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * Format time blocks as readable text.
 *
 * @param {unknown} value The `blocks` array: `[{startsAt, endsAt}]`.
 * @param {{locale?: string, timeZone?: string}} [options] The reader's locale and time zone; the runtime's when left out.
 * @return {string} The readable blocks, or '' when there are none.
 * @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md#requirement-the-teacher-availability-list-reads-words
 */
export function formatTimeBlocks(value, options = {}) {
	if (!Array.isArray(value)) {
		return ''
	}
	const zone = options.timeZone ? { timeZone: options.timeZone } : {}
	const day = new Intl.DateTimeFormat(options.locale, {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		...zone,
	})
	const time = new Intl.DateTimeFormat(options.locale, {
		hour: '2-digit',
		minute: '2-digit',
		...zone,
	})

	return value
		.map((block) => {
			const start = toDate(block && block.startsAt)
			const end = toDate(block && block.endsAt)
			if (start === null) {
				return ''
			}
			const from = `${day.format(start)}, ${time.format(start)}`
			if (end === null) {
				return from
			}
			const sameDay = day.format(end) === day.format(start)
			const until = sameDay
				? time.format(end)
				: `${day.format(end)}, ${time.format(end)}`
			return `${from}–${until}`
		})
		.filter((text) => text !== '')
		.join('; ')
}

/**
 * The formatters learniq adds to the library's built-ins, for CnAppRoot.
 *
 * @param {function(): string} getLocale The reader's locale, e.g. `nl-NL`.
 * @return {{timeBlocks: function(unknown): string}}
 * @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md#requirement-the-teacher-availability-list-reads-words
 */
export function createFormatters(getLocale) {
	return {
		timeBlocks: (value) => formatTimeBlocks(value, { locale: getLocale() }),
	}
}
