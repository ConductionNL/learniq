// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The administration's Today (exam-registration-and-certificate-issue T6):
// the exam registration that asks for attention first, the course days whose
// certificates are to be issued, the certificates expiring before 1 January
// and the coming exams. Reads the answer of GET /api/exam-registrations/today.
// The sentences of the registration card come from the server in Dutch, as
// the board writes them. Pure, tested in tests/unit-js/administrationToday.test.mjs.

import { dayAndMonth } from './plannerToday.js'

/**
 * The registration list that comes first on Today, as its card reads it.
 *
 * @param {object|null} today The answer.
 * @return {{id: string, closingLine: string, summary: string, people: Array<{key: string, sentence: string, notice: string, contactName: string}>}|null} The card, or null when no list is open.
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */
export function registrationCard(today) {
	const first = today?.first
	if (!first || typeof first !== 'object') {
		return null
	}
	const people = (Array.isArray(first.incomplete) ? first.incomplete : []).map((row, index) => ({
		key: String(row.enrolmentId ?? index),
		sentence: String(row.sentence ?? ''),
		notice: String(row.notice ?? ''),
		contactName: String(row.contactName ?? ''),
	}))
	return {
		id: String(first.id ?? ''),
		closingLine: String(first.closingLine ?? ''),
		summary: String(first.summary ?? ''),
		people,
	}
}

/**
 * The course days in rounding off, oldest first.
 *
 * @param {object|null} today The answer.
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{id: string, name: string, date: string}>} The rows.
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
 */
export function issueRows(today, locale) {
	return (Array.isArray(today?.toIssue) ? today.toIssue : []).map((day) => ({
		id: String(day.cohortId ?? ''),
		name: String(day.name ?? ''),
		date: dayAndMonth(day.dayDate ?? null, locale),
	}))
}

/**
 * The certificates expiring before the list's date, soonest first.
 *
 * @param {object|null} today The answer.
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{id: string, learner: string, course: string, expires: string, renewal: string, booked: boolean}>} The rows.
 * @spec openspec/changes/exam-registration-and-certificate-issue/tasks.md
 */
export function expiringRows(today, locale) {
	const rows = Array.isArray(today?.expiring?.rows) ? today.expiring.rows : []
	return rows.map((row) => ({
		id: String(row.credentialId ?? ''),
		learner: String(row.learnerName ?? ''),
		course: String(row.courseName ?? ''),
		expires: dayAndMonth(row.expiresOn ?? null, locale),
		renewal: String(row.renewalLine ?? ''),
		booked: row.renewal !== null && row.renewal !== undefined,
	}))
}

/**
 * The exams still to come, soonest first.
 *
 * @param {object|null} today The answer.
 * @param {string} locale The BCP 47 locale.
 * @return {Array<{id: string, date: string, course: string, body: string, participants: number}>} The rows.
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */
export function comingExamRows(today, locale) {
	return (Array.isArray(today?.comingExams) ? today.comingExams : []).map((exam) => ({
		id: String(exam.registrationId ?? ''),
		date: dayAndMonth(exam.examDate ?? null, locale),
		course: String(exam.courseName ?? ''),
		body: String(exam.bodyName ?? ''),
		participants: Number(exam.participantCount) || 0,
	}))
}

/**
 * Whether the answer has anything to show at all.
 *
 * @param {object|null} today The answer.
 * @return {boolean} True when a list, a day, a certificate or an exam is on it.
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
 */
export function hasAnything(today) {
	return registrationCard(today) !== null
		|| issueRows(today, 'en').length > 0
		|| expiringRows(today, 'en').length > 0
		|| comingExamRows(today, 'en').length > 0
}
