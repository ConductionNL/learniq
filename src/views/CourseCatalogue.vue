<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CourseCatalogue: the learner course catalogue (route /catalogue,
 enrolment-catalogue-self-signup).

 Published courses and programmes that are open or on request, from learniq's
 GET /api/catalogue, with search and filters on level, language, subject and
 provider. Each card offers what fits: Sign up (open), Request a place (on
 request), Withdraw (an own self sign-up without progress), or says the
 learner is signed up or waiting. The writes go through learniq's own
 endpoints, which check every rule and write for the caller only.

 @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
-->
<template>
	<div class="course-catalogue">
		<h2>{{ t('learniq', 'Course catalogue') }}</h2>
		<form class="course-catalogue__filters" @submit.prevent="load">
			<NcTextField v-model="search" :label="t('learniq', 'Search courses')" />
			<NcSelect
				v-model="provider"
				:options="providers"
				:inputLabel="t('learniq', 'Provider')"
				:clearable="true"
				@update:modelValue="load" />
			<NcSelect
				v-model="language"
				:options="languages"
				:inputLabel="t('learniq', 'Language')"
				:clearable="true"
				@update:modelValue="load" />
			<NcButton type="submit" variant="secondary">
				{{ t('learniq', 'Search') }}
			</NcButton>
		</form>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="entries.length === 0"
			:name="t('learniq', 'Nothing to sign up for')"
			:description="
				t('learniq', 'No course or programme is open for sign-up right now.')
			" />
		<ul v-else class="course-catalogue__cards">
			<li
				v-for="entry in entries"
				:key="entry.kind + entry.id"
				class="course-catalogue__card">
				<h3>{{ entry.name }}</h3>
				<p class="course-catalogue__meta">
					<span v-if="entry.kind === 'programme'"
						>{{ t('learniq', 'Programme') }} ·
					</span>
					<span v-if="entry.provider"
						>{{
							t('learniq', 'Provider: {provider}', {
								provider: entry.provider,
							})
						}}
						·
					</span>
					<span v-if="entry.level">{{ entry.level }} · </span>
					<span v-if="entry.language">{{ entry.language }}</span>
					<span v-if="entry.ectsCredits">
						·
						{{
							t('learniq', '{credits} credits', {
								credits: entry.ectsCredits,
							})
						}}</span
					>
				</p>
				<p class="course-catalogue__description">
					{{ shorten(entry.description) }}
				</p>
				<p
					v-if="entry.enrolment && entry.enrolment.lifecycle === 'active'"
					class="course-catalogue__state">
					{{ t('learniq', 'You are signed up') }}
				</p>
				<p
					v-else-if="
						entry.enrolment && entry.enrolment.lifecycle === 'pending'
					"
					class="course-catalogue__state">
					{{ t('learniq', 'Your request is waiting for approval') }}
				</p>
				<div class="course-catalogue__actions">
					<NcButton
						v-if="canWithdraw(entry)"
						variant="tertiary"
						:disabled="busy === entry.id"
						@click="withdraw(entry)">
						{{ t('learniq', 'Withdraw') }}
					</NcButton>
					<NcButton
						v-else-if="!isLive(entry)"
						variant="primary"
						:disabled="busy === entry.id"
						@click="signUp(entry)">
						{{
							entry.selfEnrolment === 'on-request'
								? t('learniq', 'Request a place')
								: t('learniq', 'Sign up')
						}}
					</NcButton>
				</div>
				<NcNoteCard v-if="notes[entry.id]" :type="notes[entry.id].type">
					{{ notes[entry.id].text }}
				</NcNoteCard>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { canWithdraw, isLive, withdrawIds } from '../utils/catalogueCard.js'

export default {
	name: 'CourseCatalogue',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			courses: [],
			programmes: [],
			providers: [],
			languages: [],
			search: '',
			provider: null,
			language: null,
			busy: '',
			notes: {},
		}
	},

	computed: {
		/**
		 * @return {Array<object>} Programmes first, then courses.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
		 */
		entries() {
			return [...this.programmes, ...this.courses]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the catalogue with the current search and filters.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#requirement-provider-courses-show-their-provider
		 */
		async load() {
			this.loading = true
			try {
				const data = (
					await axios.get(generateUrl('/apps/learniq/api/catalogue'), {
						params: {
							search: this.search || undefined,
							provider: this.provider || undefined,
							language: this.language || undefined,
						},
					})
				).data
				this.courses = data.courses ?? []
				this.programmes = data.programmes ?? []
				if (!this.provider && !this.language && !this.search) {
					this.providers = [
						...new Set(
							this.courses.map((c) => c.provider).filter(Boolean),
						),
					]
					this.languages = [
						...new Set(
							this.courses.map((c) => c.language).filter(Boolean),
						),
					]
				}
			} catch {
				this.courses = []
				this.programmes = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} entry A catalogue card.
		 * @return {boolean} Whether the learner has a pending or active enrolment.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
		 */
		isLive(entry) {
			return isLive(entry)
		},

		/**
		 * @param {object} entry A catalogue card.
		 * @return {boolean} Whether the learner may withdraw their sign-up.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
		 */
		canWithdraw(entry) {
			return canWithdraw(entry)
		},

		/**
		 * @param {string} text A description.
		 * @return {string} At most 220 characters.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
		 */
		shorten(text) {
			const value = text || ''
			return value.length > 220 ? value.slice(0, 217) + '...' : value
		},

		/**
		 * Sign up for a course or programme.
		 *
		 * @param {object} entry A catalogue card.
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-an-open-course
		 */
		async signUp(entry) {
			this.busy = entry.id
			const path = entry.kind === 'programme' ? 'programmes' : 'courses'
			try {
				const data = (
					await axios.post(
						generateUrl(
							`/apps/learniq/api/catalogue/${path}/${entry.id}/sign-up`,
						),
					)
				).data
				const refused = data.refused ?? []
				this.notes = {
					...this.notes,
					[entry.id]:
						refused.length > 0
							? {
									type: 'warning',
									text: refused.map((r) => r.message).join(' '),
								}
							: { type: 'success', text: this.t('learniq', 'Done.') },
				}
				await this.load()
			} catch (error) {
				this.notes = {
					...this.notes,
					[entry.id]: {
						type: 'error',
						text:
							error?.response?.data?.message
							?? this.t('learniq', 'Signing up did not work.'),
					},
				}
			} finally {
				this.busy = ''
			}
		},

		/**
		 * Withdraw an own sign-up: the course's enrolment, or every live
		 * enrolment a programme sign-up created.
		 *
		 * @param {object} entry A catalogue card.
		 * @return {Promise<void>}
		 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-changes-their-mind
		 */
		async withdraw(entry) {
			this.busy = entry.id
			try {
				for (const id of withdrawIds(entry)) {
					await axios.post(
						generateUrl(`/apps/learniq/api/enrolments/${id}/withdraw`),
					)
				}
				await this.load()
			} catch (error) {
				this.notes = {
					...this.notes,
					[entry.id]: {
						type: 'error',
						text:
							error?.response?.data?.message
							?? this.t('learniq', 'Withdrawing did not work.'),
					},
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.course-catalogue {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.course-catalogue__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.course-catalogue__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	list-style: none;
	padding: 0;
}

.course-catalogue__card {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.course-catalogue__meta,
.course-catalogue__state {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
