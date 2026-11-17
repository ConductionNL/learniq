<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PlannerTodayWidget: the planner's Today card for course days
 (training-provider-course-days T6). Shows the answer of
 GET /api/course-days/today, which LearniqDashboards reads once and only
 shows to a holder of the course-day.plan action: first the course days
 under their minimum whose go/no-go decision is due, each with the next day
 of the course that has places; then the bookings and enrolments to confirm;
 then the occupancy, trainers and rooms of the coming course days.

 @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six
-->
<!-- @visual exclude Dashboard card rendered inside LearniqDashboards (planner); the visual surface is LearniqDashboards, which carries the baseline. -->
<template>
	<div class="planner-today" data-testid="planner-today-widget">
		<ul v-if="decisions.length > 0" class="planner-today__decisions">
			<li v-for="day in decisions" :key="day.id" class="planner-today__decision">
				<strong>{{ participantsLine(day) }}</strong>
				<span v-if="day.decideBy">{{ t('learniq', 'Decide by {date} whether the day goes ahead.', { date: day.decideBy }) }}</span>
				<span v-if="day.next">{{ freeLine(day.next) }}</span>
				<span v-else>{{ t('learniq', 'No other day of this course has places.') }}</span>
				<router-link :to="'/cohorts/' + day.id">
					{{ t('learniq', 'Open the course day') }}
				</router-link>
			</li>
		</ul>
		<p v-else class="planner-today__empty">
			{{ t('learniq', 'No course day waits for a decision.') }}
		</p>

		<p v-if="toConfirm > 0" class="planner-today__confirm">
			{{ n('learniq', '%n enrolment to confirm', '%n enrolments to confirm', toConfirm) }}
		</p>

		<template v-if="occupancy.length > 0">
			<h3 class="planner-today__heading">
				{{ t('learniq', 'Occupancy until {date}', { date: until }) }}
			</h3>
			<table class="planner-today__occupancy">
				<thead>
					<tr>
						<th scope="col">
							{{ t('learniq', 'Course day') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Date') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Participants') }}
						</th>
						<th scope="col">
							{{ t('learniq', 'Trainers and rooms') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in occupancy" :key="row.id">
						<td>
							<router-link :to="'/cohorts/' + row.id">
								{{ row.label }}
							</router-link>
						</td>
						<td>{{ row.date }}</td>
						<td :class="{ 'planner-today__short': row.short }">
							{{ takenLine(row) }}
						</td>
						<td :class="{ 'planner-today__short': row.unstaffed }">
							{{ staffLine(row) }}
						</td>
					</tr>
				</tbody>
			</table>
			<router-link to="/course-days">
				{{ t('learniq', 'All course days') }}
			</router-link>
		</template>
	</div>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import {
	dayAndMonth,
	decisionCards,
	occupancyRows,
	toConfirmCount,
} from '../../utils/plannerToday.js'

export default {
	name: 'PlannerTodayWidget',

	props: {
		/** The answer of GET /api/course-days/today. */
		today: { type: Object, default: null },
	},

	computed: {
		/**
		 * @return {string} The reader's language.
		 * @spec exclude Presentation-only locale lookup.
		 */
		locale() {
			return getLanguage() || 'en'
		},

		/**
		 * The course days that ask for a decision now.
		 *
		 * @return {Array<object>} The cards.
		 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#scenario-four-of-six
		 */
		decisions() {
			return decisionCards(this.today, this.locale)
		},

		/**
		 * The coming course days with their occupancy, trainers and rooms.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
		 */
		occupancy() {
			return occupancyRows(this.today, this.locale)
		},

		/**
		 * @return {number} The bookings and enrolments to confirm.
		 * @spec openspec/changes/training-provider-course-days/specs/course-management/spec.md#requirement-a-course-day-has-a-minimum-a-decision-date-and-a-status-that-moves-by-itself
		 */
		toConfirm() {
			return toConfirmCount(this.today)
		},

		/**
		 * @return {string} The last day of the occupancy.
		 * @spec exclude Presentation-only date formatting.
		 */
		until() {
			return dayAndMonth(this.today?.occupancy?.until ?? null, this.locale)
		},
	},

	methods: {
		/**
		 * @param {object} day A decision card.
		 * @return {string} "Hybride warmtepomp on 22 October has 4 participants. The minimum is 6."
		 * @spec exclude Presentation-only sentence lookup.
		 */
		participantsLine(day) {
			return this.n(
				'learniq',
				'{course} on {date} has %n participant. The minimum is {minimum}.',
				'{course} on {date} has %n participants. The minimum is {minimum}.',
				day.participants,
				{ course: day.course, date: day.date, minimum: day.minimum },
			)
		},

		/**
		 * @param {{date: string, free: number|null}} next The next day with places.
		 * @return {string} The sentence.
		 * @spec exclude Presentation-only sentence lookup.
		 */
		freeLine(next) {
			if (next.free === null) {
				return this.t('learniq', 'On {date} there are still places free.', { date: next.date })
			}
			return this.n('learniq', 'On {date} there is still %n place free.', 'On {date} there are still %n places free.', next.free, { date: next.date })
		},

		/**
		 * @param {{taken: number, capacity: number|null}} row An occupancy row.
		 * @return {string} "4 of 12", or the count without a maximum.
		 * @spec exclude Presentation-only sentence lookup.
		 */
		takenLine(row) {
			return row.capacity === null
				? String(row.taken)
				: this.t('learniq', '{taken} of {capacity}', { taken: row.taken, capacity: row.capacity })
		},

		/**
		 * @param {{trainers: number, rooms: Array<string>}} row An occupancy row.
		 * @return {string} What is set, or what is missing.
		 * @spec exclude Presentation-only sentence lookup.
		 */
		staffLine(row) {
			const trainers = row.trainers === 0
				? this.t('learniq', 'No trainer')
				: this.n('learniq', '%n trainer', '%n trainers', row.trainers)
			const rooms = row.rooms.length === 0 ? this.t('learniq', 'No room') : row.rooms.join(', ')
			return trainers + ' · ' + rooms
		},
	},
}
</script>

<style scoped>
.planner-today {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.planner-today__decisions {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.planner-today__decision {
	display: flex;
	flex-direction: column;
}

.planner-today__empty {
	color: var(--color-text-maxcontrast);
}

.planner-today__heading {
	font-size: var(--default-font-size);
	font-weight: bold;
	margin: 0;
}

.planner-today__occupancy {
	width: 100%;
	border-collapse: collapse;
}

.planner-today__occupancy th,
.planner-today__occupancy td {
	text-align: start;
	padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 2);
	border-bottom: 1px solid var(--color-border);
}

.planner-today__short {
	color: var(--color-text-error, var(--color-error-text));
	font-weight: bold;
}
</style>
