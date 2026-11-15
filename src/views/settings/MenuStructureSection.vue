<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MenuStructureSection: which structure the app shows, the simple one (the
 default) or the full one.

 The choice is read at page load by the app's boot code, before it builds the
 navigation, so a change shows the next time somebody opens learniq. The
 section says so, because a setting that seems to do nothing gets changed back.

 @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
-->
<template>
	<NcSettingsSection
		id="section-menu-structure"
		:name="t('learniq', 'Menu structure')"
		:description="
			t('learniq', 'Choose how much the menu shows. Simple is the default.')
		">
		<p v-if="loading">
			{{ t('learniq', 'Loading the menu structure…') }}
		</p>
		<div v-else-if="loaded" class="menu-structure" data-testid="menu-structure">
			<fieldset class="menu-structure__choices" :disabled="saving">
				<legend class="hidden-visually">
					{{ t('learniq', 'Menu structure') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-model="structure"
					value="simple"
					name="menu_structure"
					type="radio"
					data-testid="menu-structure-simple"
					@update:modelValue="save">
					{{ t('learniq', 'Simple') }}
				</NcCheckboxRadioSwitch>
				<p class="menu-structure__help">
					{{
						t(
							'learniq',
							'At most ten menu entries for each role. Everything else is one step further, in settings or on a page.',
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-model="structure"
					value="full"
					name="menu_structure"
					type="radio"
					data-testid="menu-structure-full"
					@update:modelValue="save">
					{{ t('learniq', 'Full') }}
				</NcCheckboxRadioSwitch>
				<p class="menu-structure__help">
					{{ t('learniq', 'Every entry in the menu, as it was before.') }}
				</p>
			</fieldset>
			<p class="menu-structure__note" role="status">
				{{
					saved
						? t(
								'learniq',
								'Saved. People see the change the next time they open Learniq.',
							)
						: t(
								'learniq',
								'No page is removed. Both menus open the same pages.',
							)
				}}
			</p>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import {
	loadMenuStructure,
	saveMenuStructure,
} from '../../services/menuStructureSetting.js'
import { STRUCTURE_SIMPLE } from '../../utils/structureProfile.js'

const SETTINGS_URL = '/apps/learniq/api/settings'

/**
 * The menu structure choice on the admin settings page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */
export default {
	name: 'MenuStructureSection',
	components: { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection },
	data() {
		return {
			structure: STRUCTURE_SIMPLE,
			stored: STRUCTURE_SIMPLE,
			loading: true,
			loaded: false,
			saving: false,
			saved: false,
			error: null,
		}
	},

	/**
	 * Read the stored structure. Until it is known no radio is offered, so a
	 * click cannot save over a value the section has not seen.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
	 */
	async created() {
		try {
			this.stored = await loadMenuStructure(axios, generateUrl(SETTINGS_URL))
			this.structure = this.stored
			this.loaded = true
		} catch {
			this.error = t(
				'learniq',
				'The menu structure could not be loaded. Reload the page to try again.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * Store the chosen structure, and put the radio back when that fails.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
		 */
		async save() {
			if (this.structure === this.stored) {
				return
			}
			this.saving = true
			this.saved = false
			this.error = null
			try {
				this.stored = await saveMenuStructure(
					axios,
					generateUrl(SETTINGS_URL),
					this.structure,
				)
				this.saved = true
			} catch {
				this.structure = this.stored
				this.error = t('learniq', 'The menu could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.menu-structure__choices {
	border: 0;
	margin: 0;
	padding: 0;
}

.menu-structure__help {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 9);
}

.menu-structure__note {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
