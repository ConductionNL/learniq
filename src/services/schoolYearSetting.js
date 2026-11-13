// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The first day of the school year on the admin settings page
 * (school-year-setting): stored as `MM-DD` in the app setting
 * `school_year_start`, read by lib/Service/Calendar/SchoolYear.php, and
 * 1 August when nothing usable is stored. The checks here mirror that class, so
 * the page never offers a value the server would read as the default.
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */

/** The app setting the server reads. */
export const SCHOOL_YEAR_SETTING = 'school_year_start'

/** The start a missing or unusable setting reads as. */
export const DEFAULT_SCHOOL_YEAR_START = '08-01'

/**
 * A day of the year as `MM-DD`, or null when it is not one. 29 February is
 * refused, as the server checks the day against a year that is not a leap year.
 *
 * @param {unknown} raw The value.
 * @return {string|null} The start.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export function parseSchoolYearStart(raw) {
	const text = String(raw ?? '').trim()
	const match = /^(\d{2})-(\d{2})$/.exec(text)
	if (!match) {
		return null
	}
	const month = Number(match[1])
	const day = Number(match[2])
	const check = new Date(Date.UTC(2001, month - 1, day))
	if (month < 1 || month > 12 || check.getUTCMonth() !== month - 1 || check.getUTCDate() !== day) {
		return null
	}
	return text
}

/**
 * The start as a date in a given year, for a date picker.
 *
 * @param {string} start The start, `MM-DD`.
 * @param {number} year The year.
 * @return {Date} Local midnight of that day.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export function startInYear(start, year) {
	const [month, day] = start.split('-').map(Number)
	return new Date(year, month - 1, day)
}

/**
 * The start a picked date stands for: its month and day.
 *
 * @param {Date} date The picked date.
 * @return {string|null} The start, or null for no date.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export function startOfDate(date) {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
		return null
	}
	const pad = (n) => String(n).padStart(2, '0')
	return parseSchoolYearStart(`${pad(date.getMonth() + 1)}-${pad(date.getDate())}`)
}

/**
 * The school year a date falls in: it started on the last start day on or
 * before the date, ends the day before the next one, and is written
 * `YYYY-YYYY` after the calendar years it spans.
 *
 * @param {Date} date The date.
 * @param {string} start The start, `MM-DD`.
 * @return {{label: string, from: Date, until: Date}} The school year.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export function schoolYearOf(date, start) {
	const day = new Date(date.getFullYear(), date.getMonth(), date.getDate())
	let from = startInYear(start, day.getFullYear())
	if (from > day) {
		from = startInYear(start, day.getFullYear() - 1)
	}
	const next = startInYear(start, from.getFullYear() + 1)
	const until = new Date(next.getFullYear(), next.getMonth(), next.getDate() - 1)
	return { label: `${from.getFullYear()}-${until.getFullYear()}`, from, until }
}

/**
 * The stored start, or 1 August.
 *
 * @param {{http: object, url: string}} endpoint The settings endpoint.
 * @return {Promise<string>} The start.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export async function loadSchoolYearStart(endpoint) {
	const { data } = await endpoint.http.get(endpoint.url)
	return parseSchoolYearStart(data?.[SCHOOL_YEAR_SETTING]) ?? DEFAULT_SCHOOL_YEAR_START
}

/**
 * Store the start; an answer that did not keep it is an error.
 *
 * @param {{http: object, url: string}} endpoint The settings endpoint.
 * @param {string} start The start, `MM-DD`.
 * @return {Promise<string>} The stored start.
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
export async function saveSchoolYearStart(endpoint, start) {
	const { data } = await endpoint.http.put(endpoint.url, {
		[SCHOOL_YEAR_SETTING]: start,
	})
	const stored = parseSchoolYearStart(data?.config?.[SCHOOL_YEAR_SETTING])
	if (stored !== start) {
		throw new Error('settings write did not store the school year start')
	}
	return stored
}
