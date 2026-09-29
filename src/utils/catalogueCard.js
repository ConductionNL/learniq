// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What a catalogue card offers, for a course or a programme alike
 * (enrolment-catalogue-self-signup).
 *
 * A course card's `enrolment` is the learner's one enrolment in it. A
 * programme sign-up creates one enrolment per course, so the programme card's
 * `enrolment` (CatalogueReader::programmeEnrolment) summarises them and lists
 * every live one in `ids`. Before that summary existed a programme card had
 * no `enrolment`, so after signing up it said "Done." and still offered
 * "Sign up".
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

/** Enrolment states that count as "signed up" (CatalogueReader::LIVE_STATES). */
export const LIVE_STATES = ['pending', 'active']

/**
 * Whether the learner holds a pending or active sign-up for the card.
 *
 * @param {object} entry A catalogue card.
 * @return {boolean} True when signed up.
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
export function isLive(entry) {
	return LIVE_STATES.includes(entry?.enrolment?.lifecycle)
}

/**
 * Whether the learner may withdraw: a live sign-up they made themselves,
 * with no progress yet. For a programme every one of its live enrolments
 * must be a self sign-up without progress (the summary says `source: 'self'`
 * and the highest progress).
 *
 * @param {object} entry A catalogue card.
 * @return {boolean} True when Withdraw is offered.
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
 */
export function canWithdraw(entry) {
	const e = entry?.enrolment
	return Boolean(
		e && isLive(entry) && e.source === 'self' && !(e.progressPercent > 0),
	)
}

/**
 * The enrolments a Withdraw on this card withdraws: every live enrolment of
 * a programme, or the course's one enrolment.
 *
 * @param {object} entry A catalogue card.
 * @return {string[]} Enrolment ids, empty when there is nothing to withdraw.
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-changes-their-mind
 */
export function withdrawIds(entry) {
	const e = entry?.enrolment
	if (!e) {
		return []
	}
	if (Array.isArray(e.ids) && e.ids.length > 0) {
		return e.ids.filter((id) => typeof id === 'string' && id !== '')
	}
	return typeof e.id === 'string' && e.id !== '' ? [e.id] : []
}
