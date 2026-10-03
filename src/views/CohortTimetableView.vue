<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CohortTimetableView: the lesson timetable of one cohort, grouped by day
 (route /cohorts/:id/timetable, learniq#947). Reads the cohort's lessons
 through the timetable endpoint, which answers from planninq's school
 timetable when planninq is installed and from learniq's Sessions otherwise
 (sessions-from-planninq), and shows them in CnTimelineView; a cancelled
 lesson stays visible and is marked. Clicking a learniq Session opens it; a
 planninq lesson is changed in the timetable system, not here.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="cohort-timetable">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="!loading && !error && source === 'planninq'" type="info">
			{{
				t(
					'learniq',
					'These lessons come from the school timetable. Changes are made there.',
				)
			}}
		</NcNoteCard>
		<CnTimelineView
			v-if="!loading && !error"
			:events="events"
			:title="t('learniq', 'Timetable: {name}', { name: cohort.name || '' })"
			:emptyLabel="t('learniq', 'No lessons are scheduled for this group.')"
			:untitledLabel="t('learniq', 'Untitled lesson')"
			:kindClassMap="{ cancelled: 'cohort-timetable__cancelled' }"
			sort="asc"
			@eventClick="open" />
	</div>
</template>

<script>
import { CnTimelineView } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { fetchTimetableOf, isLearniqSession } from '../api/timetable.js'
import { objectsUrl, oneObject, timelineEvents } from '../utils/customPages.js'

/**
 * Eight weeks from this week's Monday, as ISO bounds.
 *
 * @return {string[]} The window start and end.
 */
function eightWeeks() {
	const monday = new Date()
	monday.setHours(0, 0, 0, 0)
	monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
	const end = new Date(monday)
	end.setDate(monday.getDate() + 56)
	return [monday.toISOString(), end.toISOString()]
}

export default {
	name: 'CohortTimetableView',

	components: { CnTimelineView, NcLoadingIcon, NcNoteCard },

	props: {
		/** Cohort UUID from the route. */
		id: { type: String, required: true },
	},

	data() {
		return {
			loading: true,
			error: '',
			cohort: {},
			events: [],
			sessions: [],
			source: 'learniq',
		}
	},

	/**
	 * Load the cohort and its lessons from the timetable endpoint.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/timetable-source/spec.md#requirement-both-timetable-pages-read-through-the-adapter-req-005
	 */
	async mounted() {
		try {
			const [cohort, timetable] = await Promise.all([
				axios.get(generateUrl(objectsUrl('cohort', this.id))),
				// Through the visibility policy (timetabling-visibility-rules):
				// eight weeks from this week's Monday, as before.
				fetchTimetableOf('cohort', this.id, ...eightWeeks()),
			])
			this.cohort = oneObject(cohort.data)
			this.sessions = timetable.sessions
			this.source = timetable.source
			this.events = timelineEvents(timetable.sessions)
		} catch {
			this.error = this.t('learniq', 'The timetable could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * @param {{id: string}} event The clicked lesson.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		open(event) {
			const session = this.sessions.find((s) => s.id === event?.id)
			if (session && isLearniqSession(session)) {
				this.$router
					.push({ name: 'SessionDetail', params: { id: event.id } })
					.catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.cohort-timetable {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

:deep(.cohort-timetable__cancelled) {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}
</style>
