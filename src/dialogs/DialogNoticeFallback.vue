<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 DialogNoticeFallback: a dialog's refusal banner while the installed CnDialog
 does not draw `notices` itself; renders nothing once it does.
 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<NcNoteCard
		v-if="notice && !libraryDraws"
		:type="noteType"
		class="dialog-notice-fallback">
		<strong>{{ notice.title }}</strong>
		{{ notice.text }}
	</NcNoteCard>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import { dialogDrawsNotices } from './dialogNotice.js'

export default {
	name: 'DialogNoticeFallback',

	components: { NcNoteCard },

	props: {
		/** The notice, or null for none. */
		notice: { type: Object, default: null },
	},

	computed: {
		/**
		 * @return {boolean} Whether CnDialog draws the notice itself.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		libraryDraws() {
			return dialogDrawsNotices()
		},

		/**
		 * @return {string} The NcNoteCard type for the notice's variant.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		noteType() {
			return ['success', 'warning', 'info'].includes(this.notice?.variant) ? this.notice.variant : 'error'
		},
	},
}
</script>
