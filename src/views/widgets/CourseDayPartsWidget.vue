<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CourseDayPartsWidget: the trainer's Today card for a course day split into
 parts (training-provider-course-days T5). For each of today's sessions in
 the teacher's scope that has parts, it asks
 GET /api/course-days/sessions/{sessionId}/parts and, when a part has begun
 and has no attendance yet, says so ("The afternoon has begun. Fill in the
 attendance.") with how many were there in the part before it, and links to
 the register. The endpoint refuses anyone but a trainer of the day or a
 planner; a refused session is left out.

 @spec openspec/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
-->
<!-- @visual exclude Dashboard card rendered inside LearniqDashboards (teacher role); the visual surface is LearniqDashboards, which carries the baseline. -->
<template>
	<div class="course-day-parts" data-testid="course-day-parts-widget">
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="notices.length === 0" class="course-day-parts__empty">
			{{ t('learniq', 'No part of a course day waits for its attendance.') }}
		</p>
		<ul v-else class="course-day-parts__list">
			<li v-for="notice in notices" :key="notice.sessionId" class="course-day-parts__item">
				<strong>{{ notice.title }}</strong>
				<span>{{ dueLine(notice.due) }}</span>
				<span v-if="notice.earlier">{{ earlierLine(notice.earlier) }}</span>
				<router-link :to="notice.route">
					{{ t('learniq', 'Fill in the attendance') }}
				</router-link>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { objectId } from '../../utils/customPages.js'
import { partDueNotice, sessionsWithPartsToday } from '../../utils/partDue.js'
import { rollCallRoute } from '../../utils/rollCall.js'
import { appendFilter, filterMatchesNothing } from '../../utils/teacherScope.js'

/** A trainer has a few sessions a day; this bounds the read. */
const SESSION_LIMIT = 20

export default {
	name: 'CourseDayPartsWidget',
	components: { NcLoadingIcon },

	props: {
		/** The teacher's session filter for today (sessionsToMark). */
		filter: { type: Object, default: () => ({}) },
		/** True while the dashboard is still working out the filter. */
		pending: { type: Boolean, default: false },
	},

	data() {
		return { loading: false, notices: [] }
	},

	watch: {
		filter: {
			deep: true,
			/**
			 * Reload the notices when the teacher's session filter changes.
			 *
			 * @spec openspec/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
			 */
			handler() {
				this.load()
			},
		},

		/**
		 * Reload the notices once the dashboard has worked out the filter.
		 *
		 * @spec openspec/specs/attendance/spec.md#requirement-a-trainer-registers-attendance-per-part-of-the-day
		 */
		pending() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read today's sessions with parts and the part that is due on each.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/attendance/spec.md#scenario-the-afternoon-has-begun
		 */
		async load() {
			if (this.pending) {
				return
			}

			this.notices = []
			if (filterMatchesNothing(this.filter)) {
				return
			}

			this.loading = true
			try {
				const params = appendFilter(new URLSearchParams({ _limit: String(SESSION_LIMIT) }), this.filter)
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/learniq/Session?' + params.toString()))
				const rows = Array.isArray(response.data?.results) ? response.data.results : (Array.isArray(response.data) ? response.data : [])
				const notices = []
				for (const session of sessionsWithPartsToday(rows, new Date())) {
					const notice = await this.noticeFor(session)
					if (notice) {
						notices.push(notice)
					}
				}
				this.notices = notices
			} catch {
				this.notices = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The notice for one session, or null when nothing is due or the
		 * endpoint refuses.
		 *
		 * @param {object} session A Session row.
		 * @return {Promise<object|null>} The notice.
		 * @spec openspec/specs/attendance/spec.md#scenario-the-afternoon-has-begun
		 */
		async noticeFor(session) {
			const sessionId = objectId(session)
			if (!sessionId) {
				return null
			}

			try {
				const response = await axios.get(generateUrl('/apps/learniq/api/course-days/sessions/{sessionId}/parts', { sessionId }))
				const notice = partDueNotice(response.data)
				return notice ? { ...notice, sessionId, title: session.title || '', route: rollCallRoute(session) } : null
			} catch {
				return null
			}
		},

		/**
		 * @param {string} due The part that is due.
		 * @return {string} The sentence.
		 * @spec exclude Presentation-only sentence lookup.
		 */
		dueLine(due) {
			return due === 'morning'
				? this.t('learniq', 'The morning has begun. Fill in the attendance.')
				: this.t('learniq', 'The afternoon has begun. Fill in the attendance.')
		},

		/**
		 * @param {{key: string, there: number, of: number}} earlier The part before.
		 * @return {string} The sentence.
		 * @spec exclude Presentation-only sentence lookup.
		 */
		earlierLine(earlier) {
			return this.t('learniq', 'This morning there were {there} of {of}', { there: earlier.there, of: earlier.of })
		},
	},
}
</script>

<style scoped>
.course-day-parts__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.course-day-parts__item {
	display: flex;
	flex-direction: column;
}

.course-day-parts__empty {
	color: var(--color-text-maxcontrast);
}
</style>
