<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AdministrationTodayWidget: the administration's Today card for exams and
 certificates (exam-registration-and-certificate-issue T6). Shows the answer
 of GET /api/exam-registrations/today, which LearniqDashboards reads once and
 only shows to a holder of the exam-registration.manage action: first the
 registration list that closes soonest with somebody incomplete, then the
 course days whose certificates are to be issued, the certificates expiring
 before 1 January with their renewal, and the coming exams.

 @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
-->
<!-- @visual exclude Dashboard card rendered inside LearniqDashboards (administration); the visual surface is LearniqDashboards, which carries the baseline. -->
<template>
	<div class="administration-today" data-testid="administration-today-widget">
		<section v-if="card" class="administration-today__first">
			<strong>{{ card.closingLine }}</strong>
			<span>{{ card.summary }}</span>
			<p v-for="person in card.people" :key="person.key" class="administration-today__person">
				{{ person.sentence }}
				<span v-if="person.notice">{{ person.notice }}</span>
				<span v-if="person.contactName" class="administration-today__call">
					{{ t('learniq', 'Call {name}', { name: person.contactName }) }}
				</span>
			</p>
			<router-link to="/exam-registrations">
				{{ t('learniq', 'Open the registration list') }}
			</router-link>
		</section>
		<p v-else class="administration-today__empty">
			{{ t('learniq', 'No exam registration is open.') }}
		</p>

		<template v-if="toIssue.length > 0">
			<h3 class="administration-today__heading">
				{{ t('learniq', 'To issue: all finished course days') }}
			</h3>
			<ul class="administration-today__list">
				<li v-for="day in toIssue" :key="day.id">
					<router-link :to="'/cohorts/' + day.id">
						{{ day.name }}
					</router-link>
					<span v-if="day.date"> · {{ day.date }}</span>
				</li>
			</ul>
			<router-link to="/course-days">
				{{ t('learniq', 'Issue the certificates on the course days page') }}
			</router-link>
		</template>

		<template v-if="expiring.length > 0">
			<h3 class="administration-today__heading">
				{{ t('learniq', 'Certificates expiring before {date}', { date: expiringBefore }) }}
			</h3>
			<table class="administration-today__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Participant') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Certificate') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Expires') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Renewal') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in expiring" :key="row.id">
						<td>{{ row.learner }}</td>
						<td>{{ row.course }}</td>
						<td>{{ row.expires }}</td>
						<td :class="{ 'administration-today__missing': !row.booked }">
							{{ row.renewal }}
						</td>
					</tr>
				</tbody>
			</table>
		</template>

		<template v-if="comingExams.length > 0">
			<h3 class="administration-today__heading">
				{{ t('learniq', 'Coming exams') }}
			</h3>
			<ul class="administration-today__list">
				<li v-for="exam in comingExams" :key="exam.id">
					{{ examLine(exam) }}
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import {
	comingExamRows,
	expiringRows,
	issueRows,
	registrationCard,
} from '../../utils/administrationToday.js'
import { dayAndMonth } from '../../utils/plannerToday.js'

export default {
	name: 'AdministrationTodayWidget',

	props: {
		/** The answer of GET /api/exam-registrations/today. */
		today: { type: Object, default: null },
	},

	computed: {
		/**
		 * The registration list that asks for attention first.
		 *
		 * @return {object|null} The card.
		 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
		 */
		card() {
			return registrationCard(this.today)
		},

		/**
		 * @return {string} The reader's language.
		 * @spec exclude Presentation-only locale lookup.
		 */
		locale() {
			return getLanguage() || 'en'
		},

		/**
		 * The course days in rounding off.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
		 */
		toIssue() {
			return issueRows(this.today, this.locale)
		},

		/**
		 * The certificates expiring before 1 January.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/exam-registration-and-certificate-issue/tasks.md
		 */
		expiring() {
			return expiringRows(this.today, this.locale)
		},

		/**
		 * @return {string} The date the expiring list runs to.
		 * @spec exclude Presentation-only date formatting.
		 */
		expiringBefore() {
			return dayAndMonth(this.today?.expiring?.before ?? null, this.locale)
		},

		/**
		 * The exams still to come.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-youssefs-birth-date
		 */
		comingExams() {
			return comingExamRows(this.today, this.locale)
		},
	},

	methods: {
		/**
		 * @param {{date: string, course: string, body: string, participants: number}} exam A coming exam.
		 * @return {string} "8 October: F-gassen at Examenbureau, 11 participants"
		 * @spec exclude Presentation-only sentence lookup.
		 */
		examLine(exam) {
			return this.n(
				'learniq',
				'{date}: {course} at {body}, %n participant',
				'{date}: {course} at {body}, %n participants',
				exam.participants,
				{ date: exam.date, course: exam.course, body: exam.body },
			)
		},
	},
}
</script>

<style scoped>
.administration-today {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.administration-today__first {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.administration-today__person {
	display: flex;
	flex-direction: column;
	margin: 0;
}

.administration-today__call {
	font-weight: bold;
}

.administration-today__empty {
	color: var(--color-text-maxcontrast);
}

.administration-today__heading {
	font-size: var(--default-font-size);
	font-weight: bold;
	margin: 0;
}

.administration-today__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.administration-today__table {
	width: 100%;
	border-collapse: collapse;
}

.administration-today__table th,
.administration-today__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 2);
	border-bottom: 1px solid var(--color-border);
}

.administration-today__missing {
	color: var(--color-text-maxcontrast);
}
</style>
