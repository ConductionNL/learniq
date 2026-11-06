// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the board dialogs send and when they may send it. Pure, so it runs
 * under `node --test` with nothing mocked; the dialogs only bind fields and
 * post what these functions build.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */

import { parseMark } from '../utils/customPages.js'

/**
 * @param {unknown} value A typed value.
 * @return {string} The value trimmed, '' for anything that is not a string.
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * Copy the non-empty entries of `values` onto `body`.
 *
 * @param {object} body The body to extend.
 * @param {object} values Optional values; '', null, undefined and [] are left out.
 * @return {object} The body.
 */
function withOptional(body, values) {
	for (const [key, value] of Object.entries(values)) {
		const empty = value === null || value === undefined || value === ''
			|| (Array.isArray(value) && value.length === 0)
			|| (typeof value === 'number' && Number.isNaN(value))
		if (!empty) {
			body[key] = typeof value === 'string' ? value.trim() : value
		}
	}
	return body
}

/**
 * A refusal banner in the library's notice shape.
 *
 * @param {string} title The bold first line.
 * @param {string} sentence The sentence under it.
 * @return {{variant: string, role: string, layout: string, title: string, text: string}} The notice.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function refusalNotice(title, sentence) {
	return { variant: 'error', role: 'alert', layout: 'stacked', title, text: sentence }
}

/** ConcernReport.topic, in the order the boards draw them. */
export const CONCERN_TOPICS = ['bullying', 'harassment', 'discrimination', 'violence', 'other']

/**
 * Who is filing a concern, which decides how the dialog speaks: staff from a
 * pupil's page, a guardian, or the pupil.
 *
 * @param {string} learnerId The pupil the report is about, set on the pupil's page.
 * @param {string} primaryRole The signed-in user's primary role.
 * @return {'staff'|'guardian'|'learner'} The audience.
 * @spec openspec/specs/confidential-counsel/spec.md
 */
export function concernAudience(learnerId, primaryRole) {
	if (text(learnerId) !== '') {
		return 'staff'
	}
	return primaryRole === 'guardian' ? 'guardian' : 'learner'
}

/**
 * The ConcernReport a dialog creates. The server stamps the reporter.
 *
 * @param {object} form The dialog's fields.
 * @return {object} The body.
 * @spec openspec/specs/confidential-counsel/spec.md
 */
export function concernReportBody(form) {
	return withOptional(
		{
			topic: form.topic,
			description: text(form.description),
			wantsConversation: form.wantsConversation === true,
		},
		{ happenedOn: form.happenedOn, learnerId: form.learnerId },
	)
}

/**
 * Whether a concern can be sent: a known topic and a description.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be sent.
 * @spec openspec/specs/confidential-counsel/spec.md
 */
export function canSendConcern(form) {
	return CONCERN_TOPICS.includes(form.topic) && text(form.description) !== ''
}

/** AccessibilityFeedback.severity, worst first. */
export const BARRIER_SEVERITIES = ['critical', 'serious', 'moderate', 'minor']

/**
 * The AccessibilityFeedback a user files about a barrier.
 *
 * @param {object} form The dialog's fields.
 * @return {object} The body.
 * @spec openspec/specs/accessibility-conformance/spec.md
 */
export function barrierBody(form) {
	return {
		affectedSurface: text(form.affectedSurface),
		description: text(form.description),
		severity: form.severity,
	}
}

/**
 * Whether a barrier report can be sent: where, what and how bad.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be sent.
 * @spec openspec/specs/accessibility-conformance/spec.md
 */
export function canSendBarrier(form) {
	const body = barrierBody(form)
	return body.affectedSurface !== '' && body.description !== '' && BARRIER_SEVERITIES.includes(body.severity)
}

/** The reasons a certificate is withdrawn for, as the revoke dialog offers them. */
export const REVOKE_REASONS = ['issued-in-error', 'requirements-not-met', 'fraud', 'replaced', 'other']

/**
 * The reason a revoked certificate carries: the chosen reason, then the
 * explanation when there is one.
 *
 * @param {string} reasonLabel The chosen reason as the user read it.
 * @param {string} explanation The optional explanation.
 * @return {string} The revocation reason.
 * @spec openspec/specs/certification/spec.md
 */
export function revocationReason(reasonLabel, explanation) {
	const more = text(explanation)
	return more === '' ? text(reasonLabel) : `${text(reasonLabel)}: ${more}`
}

/** Cohort.kind, as the new-group choice cards order them. */
export const COHORT_KINDS = ['teaching', 'care', 'plusklas']

/**
 * The Cohort a new-group dialog creates. It starts `planned`; the lifecycle
 * moves it on.
 *
 * @param {object} form The dialog's fields.
 * @param {string|null} tenant The caller's tenant.
 * @return {object} The body.
 * @spec openspec/specs/school-structure/spec.md
 */
export function cohortBody(form, tenant) {
	const year = Number.parseInt(form.programmeYear, 10)
	return withOptional(
		{
			name: text(form.name),
			academicYear: text(form.academicYear),
			period: text(form.period),
			kind: COHORT_KINDS.includes(form.kind) ? form.kind : 'teaching',
		},
		{
			programmeId: form.programmeId,
			programmeYear: Number.isNaN(year) ? null : year,
			teacherIds: form.teacherIds,
			learnerIds: form.learnerIds,
			courseId: form.courseId,
			locationId: form.locationId,
			ncGroupId: form.ncGroupId,
			reportCardTemplateId: form.reportCardTemplateId,
			notes: form.notes,
			tenant_id: tenant,
		},
	)
}

/**
 * Whether a group can be made: a name, a school year and a period.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be made.
 * @spec openspec/specs/school-structure/spec.md
 */
export function canCreateCohort(form) {
	return text(form.name) !== '' && text(form.academicYear) !== '' && text(form.period) !== ''
}

/** DossierNote.category, in the order the board draws them. */
export const NOTE_CATEGORIES = ['observation', 'conversation', 'phone-call-home', 'concern', 'positive']

/** DossierNote.confidentiality, widest first. */
export const NOTE_CONFIDENTIALITY = ['team-visible', 'care-team-only', 'private-to-author']

/**
 * The DossierNote a dialog creates. The care team only travels with a note
 * that is for the care team.
 *
 * @param {object} form The dialog's fields.
 * @param {string} authorId The signed-in user.
 * @param {string|null} tenant The caller's tenant.
 * @return {object} The body.
 * @spec openspec/specs/pupil-dossier/spec.md
 */
export function dossierNoteBody(form, authorId, tenant) {
	return withOptional(
		{
			learnerId: text(form.learnerId),
			authorId,
			date: text(form.date),
			category: form.category,
			body: text(form.body),
			confidentiality: form.confidentiality,
		},
		{
			careTeamUserIds: form.confidentiality === 'care-team-only' ? form.careTeamUserIds : [],
			tenant_id: tenant,
		},
	)
}

/**
 * Whether a dossier note can be saved: a pupil, a day, a kind, the note and
 * who sees it.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be saved.
 * @spec openspec/specs/pupil-dossier/spec.md
 */
export function canSaveDossierNote(form) {
	return text(form.learnerId) !== ''
		&& text(form.date) !== ''
		&& NOTE_CATEGORIES.includes(form.category)
		&& text(form.body) !== ''
		&& NOTE_CONFIDENTIALITY.includes(form.confidentiality)
}

/**
 * The ConfidentialNote a counsellor creates.
 *
 * @param {object} form The dialog's fields.
 * @param {string} authorId The signed-in counsellor.
 * @param {string|null} tenant The caller's tenant.
 * @return {object} The body.
 * @spec openspec/specs/confidential-counsel/spec.md
 */
export function confidentialNoteBody(form, authorId, tenant) {
	return withOptional(
		{
			title: text(form.title),
			note: text(form.note),
			authorId,
			participantIds: Array.isArray(form.participantIds) ? form.participantIds : [],
			status: form.status === 'closed' ? 'closed' : 'open',
		},
		{ destroyAfter: form.destroyAfter, tenant_id: tenant },
	)
}

/**
 * Whether a confidential note can be saved: a case name and the note.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be saved.
 * @spec openspec/specs/confidential-counsel/spec.md
 */
export function canSaveConfidentialNote(form) {
	return text(form.title) !== '' && text(form.note) !== ''
}

/**
 * The transitions that decide an exemption case: a case still `submitted`
 * goes into assessment first.
 *
 * @param {string} lifecycle The case's state.
 * @param {'grant'|'reject'} decision The decision.
 * @return {string[]} The transitions, in order.
 * @spec openspec/specs/exam-board/spec.md
 */
export function exemptionSteps(lifecycle, decision) {
	return lifecycle === 'submitted' ? ['startAssessment', decision] : [decision]
}

/**
 * Whether the exam board can decide: a decision and its rationale.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be sent.
 * @spec openspec/specs/exam-board/spec.md
 */
export function canDecideExemption(form) {
	return ['grant', 'reject'].includes(form.decision) && text(form.decisionRationale) !== ''
}

/**
 * The inputs of a decision transition.
 *
 * @param {object} form The dialog's fields.
 * @return {object} The transition data.
 * @spec openspec/specs/exam-board/spec.md
 */
export function decisionData(form) {
	return withOptional(
		{ decisionRationale: text(form.decisionRationale) },
		{ policyReference: form.policyReference, validFrom: form.validFrom, validUntil: form.validUntil },
	)
}

/**
 * Whether a regulation exemption can be granted: a rationale, a policy
 * reference and an end date, as the board says.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be granted.
 * @spec openspec/specs/compliance-exemptions/spec.md
 */
export function canGrantRegulationExemption(form) {
	return text(form.decisionRationale) !== '' && text(form.policyReference) !== '' && text(form.validUntil) !== ''
}

/**
 * Whether a regulation exemption can be rejected: a rationale.
 *
 * @param {object} form The dialog's fields.
 * @return {boolean} True when it can be rejected.
 * @spec openspec/specs/compliance-exemptions/spec.md
 */
export function canRejectRegulationExemption(form) {
	return text(form.decisionRationale) !== ''
}

/**
 * The inputs of the Enrolment `withdraw` transition a staff member runs.
 *
 * @param {string} reason The reason as typed.
 * @return {{withdrawReason: string}} The transition data.
 * @spec openspec/specs/enrolment/spec.md
 */
export function withdrawData(reason) {
	return { withdrawReason: text(reason) }
}

/**
 * The inputs of the Submission `reopen` transition.
 *
 * @param {string} due The new hand-in moment, as a datetime-local value.
 * @param {(local: string) => string} [toIso] Turns a local moment into ISO 8601.
 * @return {{resubmissionDueAt: string}} The transition data.
 * @spec openspec/specs/assignments/spec.md
 */
export function reopenData(due, toIso = (local) => new Date(local).toISOString()) {
	return { resubmissionDueAt: toIso(text(due)) }
}

/**
 * Whether a new hand-in moment lies ahead of `now`.
 *
 * @param {string} due The new hand-in moment, as a datetime-local value.
 * @param {Date} [now] The current moment.
 * @return {boolean} True when it can be set.
 * @spec openspec/specs/assignments/spec.md
 */
export function canReopen(due, now = new Date()) {
	const moment = new Date(text(due))
	return !Number.isNaN(moment.getTime()) && moment > now
}

/**
 * Who were at a conversation, from a comma separated line.
 *
 * @param {string} line The names as typed.
 * @return {string[]} One entry per name.
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function attendeeList(line) {
	return text(line).split(',').map((name) => name.trim()).filter((name) => name !== '')
}

/**
 * The ConferenceReport a teacher writes for one booked conversation. It is a
 * draft until the `record` transition fixes it.
 *
 * @param {object} args The parts.
 * @param {object} args.slot The ConferenceSlot.
 * @param {string} args.slotId The slot's uuid.
 * @param {string} args.narrative The report.
 * @param {string} args.attendees Who were there, comma separated.
 * @param {string} args.recordedBy The signed-in teacher.
 * @param {string} args.recordedAt ISO 8601 moment.
 * @param {string|null} args.tenant The caller's tenant.
 * @return {object} The body.
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function conferenceReportBody({ slot, slotId, narrative, attendees, recordedBy, recordedAt, tenant }) {
	return withOptional(
		{
			conferenceSlotId: slotId,
			learnerId: text(slot?.learnerId),
			teacherId: text(slot?.teacherId),
			recordedBy,
			recordedAt,
		},
		{
			learnerRef: slot?.learnerRef,
			narrative,
			attendeeIds: attendeeList(attendees),
			tenant_id: tenant,
		},
	)
}

/**
 * @param {string} time A time as HH:MM.
 * @return {number} Minutes after midnight.
 */
function minutesOf(time) {
	const [hours, minutes] = String(time).split(':').map((part) => Number.parseInt(part, 10))
	return hours * 60 + minutes
}

/**
 * @param {number} minutes Minutes after midnight.
 * @return {string} The time as HH:MM.
 */
function timeOf(minutes) {
	const hours = Math.floor(minutes / 60)
	return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

/** The length of one availability toggle, in minutes. */
export const HALF_HOUR = 30

/**
 * The half hours of one evening a teacher can mark free: from its start up
 * to, not including, its end.
 *
 * @param {{from: string, until: string}} evening The evening (HH:MM).
 * @return {string[]} The start of each half hour (HH:MM).
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function eveningHalfHours(evening) {
	const out = []
	for (let at = minutesOf(evening.from); at < minutesOf(evening.until); at += HALF_HOUR) {
		out.push(timeOf(at))
	}
	return out
}

/**
 * What the free half hours of one evening amount to: from the first to the
 * end of the last, and about how many conversations fit.
 *
 * @param {string[]} picked The free half hours (HH:MM).
 * @param {number} slotMinutes The length of one conversation.
 * @param {number} bufferMinutes The pause between two conversations.
 * @return {{from: string, until: string, count: number}|null} The summary, or null when none is free.
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function eveningSummary(picked, slotMinutes, bufferMinutes) {
	if (picked.length === 0) {
		return null
	}
	const sorted = [...picked].map(minutesOf).sort((a, b) => a - b)
	const step = Math.max(1, (slotMinutes || 0) + (bufferMinutes || 0))
	return {
		from: timeOf(sorted[0]),
		until: timeOf(sorted[sorted.length - 1] + HALF_HOUR),
		count: Math.floor((sorted.length * HALF_HOUR) / step),
	}
}

/**
 * TeacherAvailability.blocks from the free half hours: neighbouring half
 * hours of one evening merge into one block.
 *
 * @param {Record<string, string[]>} pickedByDate Free half hours per date (YYYY-MM-DD).
 * @param {(local: string) => string} [toIso] Turns a local moment into ISO 8601.
 * @return {{startsAt: string, endsAt: string}[]} The blocks, by date and time.
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function availabilityBlocks(pickedByDate, toIso = (local) => new Date(local).toISOString()) {
	const blocks = []
	for (const date of Object.keys(pickedByDate).sort()) {
		const starts = [...pickedByDate[date]].map(minutesOf).sort((a, b) => a - b)
		let open = null
		for (const start of starts) {
			if (open && open.end === start) {
				open.end = start + HALF_HOUR
				continue
			}
			if (open) {
				blocks.push(open)
			}
			open = { date, start, end: start + HALF_HOUR }
		}
		if (open) {
			blocks.push(open)
		}
	}
	return blocks.map((block) => ({
		startsAt: toIso(`${block.date}T${timeOf(block.start)}:00`),
		endsAt: toIso(`${block.date}T${timeOf(block.end)}:00`),
	}))
}

/**
 * The free half hours a saved availability marks, per date, in local time.
 *
 * @param {{startsAt: string, endsAt: string}[]} blocks TeacherAvailability.blocks.
 * @return {Record<string, string[]>} Free half hours per date (YYYY-MM-DD).
 * @spec openspec/specs/parent-conferences/spec.md
 */
export function pickedFromBlocks(blocks) {
	const out = {}
	for (const block of Array.isArray(blocks) ? blocks : []) {
		const start = new Date(block.startsAt)
		const end = new Date(block.endsAt)
		if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
			continue
		}
		for (let at = start; at < end; at = new Date(at.getTime() + HALF_HOUR * 60000)) {
			const date = `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`
			out[date] = [...(out[date] ?? []), timeOf(at.getHours() * 60 + at.getMinutes())]
		}
	}
	return out
}

/**
 * A day as a reader writes it ("14 oktober 2025"), or '' for no date.
 *
 * @param {string} iso An ISO 8601 date or moment.
 * @param {string} locale The reader's language (BCP 47).
 * @param {object} [extra] More Intl.DateTimeFormat options (a weekday, a time).
 * @return {string} The day.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function dayLabel(iso, locale, extra = {}) {
	const moment = new Date(text(iso))
	if (text(iso) === '' || Number.isNaN(moment.getTime())) {
		return ''
	}
	return new Intl.DateTimeFormat(locale || 'nl', { day: 'numeric', month: 'long', year: 'numeric', ...extra }).format(moment)
}

/** GradeEntry.sourceKind, as the grade dialog offers them. */
export const GRADE_SOURCES = ['manual', 'assessment-result', 'assignment-submission', 'method-test', 'participation', 'exemption', 'portfolio']

/**
 * The plan component a grade is for.
 *
 * @param {object} plan The CurriculumPlan.
 * @param {string} componentId The component's id.
 * @return {object} The component, or {}.
 * @spec openspec/specs/grading/spec.md
 */
export function planComponent(plan, componentId) {
	return (Array.isArray(plan?.components) ? plan.components : []).find((entry) => entry.componentId === componentId) ?? {}
}

/**
 * A picked day (YYYY-MM-DD) as the start of that day in UTC, for a
 * date-time property; '' stays ''.
 *
 * @param {string} day The picked day.
 * @return {string} ISO 8601 moment, or ''.
 * @spec openspec/specs/grading/spec.md
 */
export function dayStart(day) {
	const picked = text(day)
	return /^\d{4}-\d{2}-\d{2}$/.test(picked) ? `${picked}T00:00:00Z` : picked
}

/**
 * The GradeEntry a teacher saves as a concept. An empty weight, scale or
 * period falls back to the plan component's; an empty grader is the teacher.
 *
 * @param {object} form The dialog's fields.
 * @param {object} plan The chosen CurriculumPlan.
 * @param {string} teacherId The signed-in teacher.
 * @param {string|null} tenant The caller's tenant.
 * @return {object} The body.
 * @spec openspec/specs/grading/spec.md
 */
export function gradeEntryCreateBody(form, plan, teacherId, tenant) {
	const component = planComponent(plan, form.componentId)
	const weight = parseMark(form.weight)
	return withOptional(
		{
			learnerId: text(form.learnerId),
			curriculumPlanId: text(form.curriculumPlanId),
			componentId: text(form.componentId),
			gradeScaleId: text(form.gradeScaleId) || component.gradeScaleId || plan?.gradeScaleId || '',
			sourceKind: GRADE_SOURCES.includes(form.sourceKind) ? form.sourceKind : 'manual',
			value: parseMark(form.value),
			grader: text(form.grader) || teacherId,
		},
		{
			weight: weight ?? component.weight ?? null,
			period: text(form.period) || (component.period === undefined ? '' : String(component.period)),
			courseId: form.courseId,
			courseName: form.courseName,
			cohortId: form.cohortId,
			gradedAt: dayStart(form.gradedAt),
			visibleFrom: dayStart(form.visibleFrom),
			methodName: form.methodName,
			methodBlock: form.methodBlock,
			comment: form.comment,
			tenant_id: tenant,
		},
	)
}

/**
 * Whether a grade can be saved: a pupil, a plan component, a scale and a
 * number.
 *
 * @param {object} form The dialog's fields.
 * @param {object} plan The chosen CurriculumPlan.
 * @return {boolean} True when it can be saved.
 * @spec openspec/specs/grading/spec.md
 */
export function canSaveGrade(form, plan) {
	const body = gradeEntryCreateBody(form, plan, 'x', null)
	return body.learnerId !== '' && body.curriculumPlanId !== '' && body.componentId !== ''
		&& body.gradeScaleId !== '' && body.value !== null
}

/** Who a group enrolment reaches, as the dialog's cards order them. */
export const ENROL_AUDIENCES = ['section', 'team', 'hr-function']

/**
 * The body that enrols one audience in a regulation's mandatory course.
 *
 * @param {object} form The dialog's fields.
 * @return {object} The body.
 * @spec openspec/specs/compliance-audit/spec.md
 */
export function groupEnrolBody(form) {
	return withOptional(
		{
			audienceKind: ENROL_AUDIENCES.includes(form.audienceKind) ? form.audienceKind : 'section',
			audience: text(form.audience),
		},
		{ dueDate: form.dueDate, message: form.message },
	)
}
