<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PermissionRequestOutcome: who answered a permission request yes, who
 answered no and who has not answered at all (permission-slips). A body
 section on PermissionRequestDetail.

 Diffs the children the request asks (its learnerRefs) against its
 PermissionResponses; the split itself lives in utils/permissionOutcome.js.
 Staff only, like the hand-in status.

 @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
-->
<template>
	<div v-if="allowed" class="permission-outcome">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="outcome.asked === 0"
			:name="t('learniq', 'No learners asked')"
			:description="
				t('learniq', 'Name the learners on the request to see who has answered.')
			" />
		<template v-else>
			<p class="permission-outcome__summary">
				{{
					t('learniq', '{answered} of {asked} answered', {
						answered: outcome.answered,
						asked: outcome.asked,
					})
				}}
			</p>
			<p class="permission-outcome__silence">
				{{
					outcome.silenceRefuses
						? t('learniq', 'Not answered counts as no.')
						: t('learniq', 'Not answered counts as yes.')
				}}
			</p>
			<section
				v-for="group in groups"
				:key="group.id"
				class="permission-outcome__group">
				<h3 class="permission-outcome__heading">
					{{ group.title }} ({{ group.refs.length }})
				</h3>
				<p
					v-if="group.refs.length === 0"
					class="permission-outcome__none">
					{{ t('learniq', 'Nobody') }}
				</p>
				<ul v-else class="permission-outcome__list">
					<li
						v-for="ref in group.refs"
						:key="ref"
						class="permission-outcome__row">
						{{ names[ref] || ref }}
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { canSeeHandInStatus } from '../../utils/handInStatus.js'
import { createLearnerNameResolver } from '../../utils/learnerName.js'
import { loadPermissionAnswers, permissionOutcome } from '../../utils/permissionOutcome.js'

/**
 * The decoded body of an app-relative path.
 *
 * @param {string} path The path.
 * @param {object} [params] Query parameters.
 * @return {Promise<unknown>}
 */
async function fetchJson (path, params) {
  return (await axios.get(generateUrl(path), params ? { params } : undefined)).data
}

const resolver = createLearnerNameResolver({
	getJson: async (url) => (await axios.get(url)).data,
	urlFor: generateUrl,
})

export default {
	name: 'PermissionRequestOutcome',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** PermissionRequest UUID, from the manifest's `@objectId`. */
		requestId: { type: String, default: '' },
	},

	data() {
		return {
			allowed: canSeeHandInStatus(loadState('learniq', 'dashboardRoles', [])),
			loading: true,
			loadError: '',
			request: null,
			responses: [],
			names: {},
		}
	},

	computed: {
		/**
		 * @return {object} The split of the children asked.
		 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
		 */
		outcome() {
			return permissionOutcome(this.request, this.responses)
		},

		/**
		 * Not answered first: that is the list a teacher needs before the day.
		 *
		 * @return {Array<{id: string, title: string, refs: string[]}>}
		 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
		 */
		groups() {
			return [
				{ id: 'not-answered', title: this.t('learniq', 'Not answered'), refs: this.outcome.notAnswered },
				{ id: 'yes', title: this.t('learniq', 'Answered yes'), refs: this.outcome.yes },
				{ id: 'no', title: this.t('learniq', 'Answered no'), refs: this.outcome.no },
			]
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another request.
		 *
		 * @return {void}
		 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
		 */
		requestId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the request and its answers, then the children's names.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
		 */
		async load() {
			if (!this.allowed || !this.requestId) {
				this.loading = false
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const { request, responses } = await loadPermissionAnswers(this.requestId, fetchJson)
				this.request = request
				this.responses = responses
				await this.loadNames()
			} catch {
				this.loadError = this.t(
					'learniq',
					'The answers could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Names from LearnerProfile; the ref is the fallback.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-teacher-sees-who-has-not-answered
		 */
		async loadNames() {
			const refs = [...this.outcome.notAnswered, ...this.outcome.yes, ...this.outcome.no]
			const names = await Promise.all(
				refs.map((ref) => resolver.nameOf({ ref, userId: '' })),
			)
			this.names = Object.fromEntries(refs.map((ref, i) => [ref, names[i]]))
		},
	},
}
</script>

<style scoped>
.permission-outcome__summary {
	font-weight: bold;
	margin-block-end: var(--default-grid-baseline, 4px);
}

.permission-outcome__silence,
.permission-outcome__none {
	color: var(--color-text-maxcontrast);
}

.permission-outcome__group {
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 4);
}

.permission-outcome__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.permission-outcome__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.permission-outcome__row {
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-block-end: 1px solid var(--color-border);
}
</style>
