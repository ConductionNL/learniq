<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CohortTimetableView: the lesson timetable of one cohort, grouped by day
 (route /cohorts/:id/timetable, learniq#947). Reads the cohort's Sessions
 and shows them in CnTimelineView; a cancelled lesson stays visible and is
 marked. Clicking a lesson opens it.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="cohort-timetable">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<CnTimelineView
			v-else
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
import {
	listRows,
	objectsUrl,
	oneObject,
	timelineEvents,
} from '../utils/customPages.js'

export default {
	name: 'CohortTimetableView',

	components: { CnTimelineView, NcLoadingIcon, NcNoteCard },

	props: {
		/** Cohort UUID from the route. */
		id: { type: String, required: true },
	},

	data() {
		return { loading: true, error: '', cohort: {}, events: [] }
	},

	async mounted() {
		try {
			const [cohort, sessions] = await Promise.all([
				axios.get(generateUrl(objectsUrl('cohort', this.id))),
				axios.get(generateUrl(objectsUrl('session')), {
					params: { cohortId: this.id, _limit: 1000 },
				}),
			])
			this.cohort = oneObject(cohort.data)
			this.events = timelineEvents(listRows(sessions.data))
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
			if (event?.id) {
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
