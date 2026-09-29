<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 MyElectives: a learner's optional lessons (timetabling-elective-lesson-signup).
 Open offers the learner may sign up for, each lesson with its time, free
 places and window, and Sign up or Withdraw. The server decides; a refusal
 is shown with its reason.
 @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
-->
<template>
	<div class="my-electives">
		<h2>{{ t('learniq', 'Optional lessons') }}</h2>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="loading">
			{{ t('learniq', 'Loading…') }}
		</p>
		<p v-else-if="offers.length === 0">
			{{ t('learniq', 'There are no optional lessons for you right now.') }}
		</p>
		<section v-for="offer in offers" :key="offer.id" class="my-electives__offer">
			<h3>{{ offer.name }}</h3>
			<p v-if="offer.description">
				{{ offer.description }}
			</p>
			<ul class="my-electives__lessons">
				<li
					v-for="lesson in offer.lessons"
					:key="lesson.key"
					class="my-electives__lesson"
					data-testid="elective-lesson">
					<span class="my-electives__when">{{
						formatDate(lesson.startsAt)
					}}</span>
					<span v-if="lesson.title">{{ lesson.title }}</span>
					<span>{{
						n(
							'learniq',
							'%n place free',
							'%n places free',
							lesson.freePlaces,
						)
					}}</span>
					<span v-if="lesson.mySignUpId" class="my-electives__mine">
						{{ t('learniq', 'You are signed up') }}
					</span>
					<span v-if="!lesson.windowOpen" class="my-electives__closed">
						{{ windowLabel(lesson) }}
					</span>
					<NcButton
						v-if="lesson.mySignUpId && lesson.windowOpen"
						:disabled="busy"
						@click="withdraw(lesson)">
						{{ t('learniq', 'Withdraw') }}
					</NcButton>
					<NcButton
						v-else-if="
							!lesson.mySignUpId
							&& lesson.windowOpen
							&& lesson.freePlaces > 0
						"
						variant="primary"
						:disabled="busy"
						@click="signUp(offer, lesson)">
						{{ t('learniq', 'Sign up') }}
					</NcButton>
				</li>
			</ul>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'MyElectives',

	components: {
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			offers: [],
			loading: true,
			busy: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Load the learner's open offers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/electives'),
				)
				this.offers = data?.offers || []
			} catch (e) {
				this.error = this.reason(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Sign up for a lesson.
		 *
		 * @param {object} offer The offer.
		 * @param {object} lesson The lesson.
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
		 */
		async signUp(offer, lesson) {
			await this.act(
				generateUrl('/apps/learniq/api/electives/{id}/sign-up', {
					id: offer.id,
				}),
				lesson.sessionId
					? { sessionId: lesson.sessionId }
					: { timetableSessionRef: lesson.timetableSessionRef },
			)
		},

		/**
		 * Withdraw from a lesson.
		 *
		 * @param {object} lesson The lesson.
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
		 */
		async withdraw(lesson) {
			await this.act(
				generateUrl('/apps/learniq/api/elective-sign-ups/{id}/withdraw', {
					id: lesson.mySignUpId,
				}),
				{},
			)
		},

		/**
		 * Post, then reload so places and buttons are current.
		 *
		 * @param {string} url The route.
		 * @param {object} body The body.
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
		 */
		async act(url, body) {
			this.busy = true
			this.error = ''
			try {
				await axios.post(url, body)
				await this.load()
			} catch (e) {
				this.error = this.reason(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Why a lesson takes no sign-up now.
		 *
		 * @param {object} lesson The lesson.
		 * @return {string}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
		 */
		windowLabel(lesson) {
			if (lesson.opensAt && new Date(lesson.opensAt) > new Date()) {
				return t('learniq', 'Sign-up opens {date}', {
					date: this.formatDate(lesson.opensAt),
				})
			}
			return t('learniq', 'Sign-up has closed')
		},

		/**
		 * A readable date and time.
		 *
		 * @param {string} iso A date-time.
		 * @return {string}
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
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
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
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
.my-electives {
	padding: 16px;
	max-width: 900px;
}

.my-electives__lessons {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.my-electives__lesson {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.my-electives__when {
	font-weight: bold;
}

.my-electives__mine {
	color: var(--color-success-text);
}

.my-electives__closed {
	color: var(--color-text-maxcontrast);
}
</style>
