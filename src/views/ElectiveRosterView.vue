<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ElectiveRosterView: per lesson of an optional lesson offer, who signed up
 and which eligible learners did not, with "Place" for the latter
 (timetabling-elective-lesson-signup). Placing is allowed after the window
 but never beyond the places; the server decides.
 @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
-->
<template>
	<div class="elective-roster">
		<h2>{{ roster.name || t('learniq', 'Optional lessons') }}</h2>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading…') }}
		</p>
		<section
			v-for="lesson in roster.lessons || []"
			:key="lesson.key"
			class="elective-roster__lesson"
			data-testid="roster-lesson">
			<h3>{{ formatDate(lesson.startsAt) }} {{ lesson.title }}</h3>
			<p>
				{{
					n(
						'learniq',
						'%n place free',
						'%n places free',
						lesson.freePlaces,
					)
				}}
				<span v-if="!lesson.windowOpen">
					· {{ t('learniq', 'Sign-up has closed') }}
				</span>
			</p>
			<div class="elective-roster__columns">
				<div>
					<h4>{{ t('learniq', 'Signed up') }}</h4>
					<ul>
						<li v-for="signUp in lesson.signUps" :key="signUp.id">
							{{ signUp.learnerId }}
							<span v-if="signUp.status === 'placed'"
								>({{ t('learniq', 'placed') }})</span
							>
						</li>
					</ul>
				</div>
				<div>
					<h4>{{ t('learniq', 'Not signed up') }}</h4>
					<ul>
						<li v-for="learnerId in lesson.notSignedUp" :key="learnerId">
							{{ learnerId }}
							<NcButton
								:disabled="busy || lesson.freePlaces === 0"
								@click="place(lesson, learnerId)">
								{{ t('learniq', 'Place') }}
							</NcButton>
						</li>
					</ul>
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ElectiveRosterView',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			roster: {},
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The offer's uuid from the route.
		 *
		 * @return {string}
		 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
		 */
		offerId() {
			return String(this.$route?.params?.id || '')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Load the roster.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/electives/{id}/roster', {
						id: this.offerId,
					}),
				)
				this.roster = data || {}
			} catch (e) {
				this.error = this.reason(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Place a learner on a lesson.
		 *
		 * @param {object} lesson The lesson.
		 * @param {string} learnerId The learner.
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
		 */
		async place(lesson, learnerId) {
			this.busy = true
			this.error = ''
			const where = lesson.sessionId
				? { sessionId: lesson.sessionId }
				: { timetableSessionRef: lesson.timetableSessionRef }
			try {
				await axios.post(
					generateUrl('/apps/learniq/api/electives/{id}/place', {
						id: this.offerId,
					}),
					{ learnerId, ...where },
				)
				await this.load()
			} catch (e) {
				this.error = this.reason(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * A readable date and time.
		 *
		 * @param {string} iso A date-time.
		 * @return {string}
		 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
		 */
		formatDate(iso) {
			const date = new Date(iso)
			if (!iso || Number.isNaN(date.getTime())) return ''
			return date.toLocaleString(undefined, {
				weekday: 'short',
				day: 'numeric',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * The server's reason, or a general one.
		 *
		 * @param {Error} e The failure.
		 * @return {string}
		 * @spec openspec/changes/timetabling-elective-lesson-signup/specs/enrolment/spec.md#requirement-a-coordinator-places-learners-who-missed-the-window
		 */
		reason(e) {
			return (
				e?.response?.data?.error
				|| t('learniq', 'Something went wrong. Try again.')
			)
		},
	},
}
</script>

<style scoped>
.elective-roster {
	padding: 16px;
	max-width: 1000px;
}

.elective-roster__lesson {
	padding-block: 12px;
	border-bottom: 1px solid var(--color-border);
}

.elective-roster__columns {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 24px;
}
</style>
