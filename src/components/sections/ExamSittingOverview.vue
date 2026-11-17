<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExamSittingOverview: extra time and free invigilators on an exam sitting
 (exam-sitting-overview-section). A body section on ExamSittingDetail.

 Reads the two routes the exam schedule already serves: the overview (each
 accommodated learner's end time, the invigilator places) and the colleagues
 free for the whole sitting. "Ask" writes a pending invigilator-assignment
 through OpenRegister, so the request check and the notification run as for
 any other request, then reloads both routes. A caller the routes refuse
 (not a planner, or a sitting they may not read) sees nothing of the sitting.

 @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
 @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free
-->
<template>
	<div v-if="!hidden" class="exam-sitting-overview">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<template v-else>
			<section class="exam-sitting-overview__part">
				<h3 class="exam-sitting-overview__heading">
					{{ t('learniq', 'Extra time') }}
				</h3>
				<p v-if="accommodations.length === 0" class="exam-sitting-overview__empty">
					{{ t('learniq', 'No learner in these classes has extra time') }}
				</p>
				<ul v-else class="exam-sitting-overview__list">
					<li
						v-for="row in accommodations"
						:key="row.learnerId"
						class="exam-sitting-overview__row">
						<span class="exam-sitting-overview__name">{{ learnerName(row.learnerId) }}</span>
						<span class="exam-sitting-overview__figure">{{ row.extraTimePercent }}%</span>
						<span class="exam-sitting-overview__figure">
							{{ t('learniq', 'Ends at {time}', { time: clockTime(row.endsAt) }) }}
						</span>
						<span v-if="row.separateRoom" class="exam-sitting-overview__figure">
							{{ t('learniq', 'Separate room') }}
						</span>
					</li>
				</ul>
			</section>

			<section class="exam-sitting-overview__part">
				<h3 class="exam-sitting-overview__heading">
					{{ t('learniq', 'Invigilators') }}
				</h3>
				<p class="exam-sitting-overview__places" data-testid="exam-sitting-places">
					{{ placesLine }}
				</p>
				<h4 class="exam-sitting-overview__subheading">
					{{ t('learniq', 'Free for this sitting') }}
				</h4>
				<NcNoteCard v-if="askError" type="error">
					{{ askError }}
				</NcNoteCard>
				<p v-if="free.length === 0" class="exam-sitting-overview__empty">
					{{ t('learniq', 'Nobody else is free for the whole sitting') }}
				</p>
				<ul v-else class="exam-sitting-overview__list">
					<li
						v-for="uid in free"
						:key="uid"
						class="exam-sitting-overview__row">
						<span class="exam-sitting-overview__name">{{ personName(uid) }}</span>
						<NcButton
							variant="secondary"
							:disabled="asking !== ''"
							:aria-label="t('learniq', 'Ask {name}', { name: personName(uid) })"
							@click="ask(uid)">
							{{ t('learniq', 'Ask') }}
						</NcButton>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectsUrl } from '../../utils/customPages.js'
import { fetchNamesByUser } from '../../utils/learnerName.js'

/** The exam schedule's own routes. */
const SITTINGS = '/apps/learniq/api/exam-sittings'

export default {
	name: 'ExamSittingOverview',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** Exam sitting UUID, from the manifest's `@objectId`. */
		objectId: { type: String, default: '' },
	},

	emits: ['refresh'],

	data() {
		return {
			hidden: false,
			loading: true,
			loadError: '',
			overview: null,
			free: [],
			names: {},
			people: {},
			asking: '',
			askError: '',
		}
	},

	computed: {
		/**
		 * The accommodated learners, as the overview lists them.
		 *
		 * @return {object[]} The rows.
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		accommodations() {
			return Array.isArray(this.overview?.accommodations) ? this.overview.accommodations : []
		},

		/**
		 * "1 of 3 confirmed, 1 asked, 1 open".
		 *
		 * @return {string} The line.
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free
		 */
		placesLine() {
			const places = this.overview?.invigilators ?? {}
			return this.t('learniq', '{confirmed} of {needed} confirmed, {asked} asked, {open} open', {
				confirmed: Array.isArray(places.confirmed) ? places.confirmed.length : 0,
				needed: Number(places.needed ?? 0),
				asked: Array.isArray(places.pending) ? places.pending.length : 0,
				open: Number(places.open ?? 0),
			})
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another sitting.
		 *
		 * @return {void}
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the overview and the free colleagues, then their names. A refusal
		 * (403 for a non-planner, 404 for a sitting the caller may not read)
		 * hides the section.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		async load() {
			if (!this.objectId) {
				this.hidden = true
				this.loading = false
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const base = `${SITTINGS}/${encodeURIComponent(this.objectId)}`
				const [overview, free] = await Promise.all([
					axios.get(generateUrl(`${base}/overview`)),
					axios.get(generateUrl(`${base}/available-invigilators`)),
				])
				this.overview = overview.data ?? {}
				this.free = Array.isArray(free.data?.invigilators) ? free.data.invigilators : []
				this.hidden = false
			} catch (error) {
				const status = error?.response?.status
				this.overview = null
				this.free = []
				if (status === 403 || status === 404) {
					this.hidden = true
				} else {
					this.loadError = this.t('learniq', 'The extra time and invigilators could not be loaded.')
				}
				return
			} finally {
				this.loading = false
			}
			await Promise.all([this.loadNames(), this.loadPeople()])
		},

		/**
		 * Learner names from the profiles the caller may read; none on a failure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		async loadNames() {
			if (this.accommodations.length === 0) return
			try {
				this.names = await fetchNamesByUser({
					get: (url, config) => axios.get(url, config),
					urlFor: generateUrl,
				})
			} catch {
				this.names = {}
			}
		},

		/**
		 * Display names of the free colleagues, from Nextcloud's user search.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free
		 */
		async loadPeople() {
			const people = {}
			await Promise.all(this.free.map(async (uid) => {
				try {
					const { data } = await axios.get(generateOcsUrl('core/autocomplete/get'), {
						params: { search: uid, itemType: ' ', itemId: ' ', 'shareTypes[]': 0, limit: 5 },
					})
					const match = (data?.ocs?.data ?? []).find((user) => user.id === uid)
					if (match?.label) people[uid] = match.label
				} catch {
					// The user id stays the name.
				}
			}))
			this.people = people
		},

		/**
		 * Ask a free colleague: a pending invigilation request for this sitting,
		 * written through OpenRegister. On success both routes are read again
		 * and the Invigilators list is told to refresh; a refusal shows the
		 * server's message.
		 *
		 * @param {string} uid The colleague.
		 * @return {Promise<void>}
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free
		 */
		async ask(uid) {
			if (this.asking !== '') return
			this.asking = uid
			this.askError = ''
			try {
				await axios.post(generateUrl(objectsUrl('invigilator-assignment')), {
					examSittingId: this.objectId,
					invigilatorId: uid,
				})
				await this.load()
				this.$emit('refresh')
			} catch (error) {
				const data = error?.response?.data
				this.askError = data?.message || data?.error
					|| this.t('learniq', '{name} could not be asked.', { name: this.personName(uid) })
			} finally {
				this.asking = ''
			}
		},

		/**
		 * A learner's name; "A learner" when the caller may not read the profile.
		 *
		 * @param {string} learnerId The learner's user id.
		 * @return {string} The name.
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		learnerName(learnerId) {
			return this.names[learnerId] || this.t('learniq', 'A learner')
		},

		/**
		 * A colleague's display name, else their user id.
		 *
		 * @param {string} uid The user id.
		 * @return {string} The name.
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-its-invigilator-places-and-who-is-free
		 */
		personName(uid) {
			return this.people[uid] || uid
		},

		/**
		 * The clock time of an end time, "11:30"; the raw value when it is not a date.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The time.
		 * @spec openspec/changes/exam-sitting-overview-section/specs/exam-schedule/spec.md#requirement-an-exam-sitting-shows-each-accommodated-learners-end-time
		 */
		clockTime(value) {
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) return String(value ?? '')
			return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
.exam-sitting-overview {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.exam-sitting-overview__heading,
.exam-sitting-overview__subheading {
	margin-block: 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.exam-sitting-overview__empty {
	color: var(--color-text-maxcontrast);
}

.exam-sitting-overview__places {
	font-weight: bold;
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.exam-sitting-overview__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.exam-sitting-overview__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}

.exam-sitting-overview__name {
	flex: 1 1 12rem;
}

.exam-sitting-overview__figure {
	color: var(--color-text-maxcontrast);
}
</style>
