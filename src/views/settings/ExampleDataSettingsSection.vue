<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExampleDataSettingsSection: remove a loaded example set on the admin page
 (wizard-drops-the-removal-step).

 The setup wizard only loads example data. This section lists the sets that
 are loaded and gives each its own Remove button. A removal asks first, then
 moves the set's objects to OpenRegister's trash through the setup action
 `remove-example-set-<id>`; anything the school made itself stays.

 @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
-->
<template>
	<NcSettingsSection
		id="section-example-data"
		:name="t('learniq', 'Example data')"
		:description="
			t(
				'learniq',
				'Example sets you loaded in the setup wizard. Removing a set moves its objects to the trash of OpenRegister, so you can restore them. Anything you made yourself stays.',
			)
		">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard
			v-if="result"
			:type="result.status === 'removed' ? 'success' : 'error'"
			data-testid="example-data-result">
			{{ result.message }}
		</NcNoteCard>

		<p v-if="loading">
			{{ t('learniq', 'Loading the example sets…') }}
		</p>

		<p v-else-if="sets.length === 0" data-testid="example-data-empty">
			{{ t('learniq', 'No example set is loaded.') }}
		</p>

		<ul v-else class="learniq-example-data">
			<li
				v-for="set in sets"
				:key="set.id"
				class="learniq-example-data__row"
				:data-testid="'example-set-' + set.id">
				<span class="learniq-example-data__label">{{
					t('learniq', set.label)
				}}</span>
				<NcButton
					variant="error"
					:disabled="removing !== ''"
					:aria-label="removeLabel(set)"
					@click="remove(set)">
					{{
						removing === set.id
							? t('learniq', 'Removing…')
							: t('learniq', 'Remove')
					}}
				</NcButton>
			</li>
		</ul>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showConfirmation } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import {
	EXAMPLE_SETS_URL,
	loadedSetsOf,
	removeExampleSet,
} from '../../utils/exampleSetRemoval.js'

export default {
	name: 'ExampleDataSettingsSection',

	components: {
		NcButton,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		return {
			sets: [],
			loading: true,
			removing: '',
			error: '',
			result: null,
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * The Remove button's accessible name, naming the set.
		 *
		 * @param {{id: string, label: string}} set The set.
		 * @return {string} The name.
		 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
		 */
		removeLabel(set) {
			return this.t('learniq', 'Remove the example set "{set}"', {
				set: this.t('learniq', set.label),
			})
		},

		/**
		 * Read the loaded sets.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl(EXAMPLE_SETS_URL))
				this.sets = loadedSetsOf(data)
			} catch {
				this.error = this.t(
					'learniq',
					'The example sets could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask, remove one set, say what happened, and read the list again.
		 *
		 * @param {{id: string, label: string}} set The set.
		 * @return {Promise<void>}
		 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
		 */
		async remove(set) {
			this.result = null
			this.removing = set.id
			try {
				const outcome = await removeExampleSet(set, {
					confirm: (chosen) =>
						showConfirmation({
							name: this.t('learniq', 'Remove example data'),
							text: this.t(
								'learniq',
								'Remove the example set "{set}"? Its objects move to the trash of OpenRegister. Anything you made yourself stays.',
								{ set: this.t('learniq', chosen.label) },
							),
							labelConfirm: this.t('learniq', 'Remove'),
							labelReject: this.t('learniq', 'Cancel'),
						}),
					post: (url) => axios.post(generateUrl(url)),
					t: (text, vars) => this.t('learniq', text, vars),
				})
				if (outcome.status !== 'cancelled') {
					this.result = outcome
					await this.load()
				}
			} finally {
				this.removing = ''
			}
		},
	},
}
</script>

<style scoped>
.learniq-example-data {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 600px;
}

.learniq-example-data__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
