<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CheckInBoard: the self check-in code full screen, for the classroom board
 (board LqLes "Zelf inchecken, op het bord"). Escape or "Back" returns to the
 self check-in dialog; the window stays open until the teacher closes it there.

 @spec openspec/specs/attendance/spec.md#scenario-a-teacher-shows-the-check-in-code-on-the-board
-->
<template>
	<CnDisplayScreen
		:title="t('learniq', 'Check in with this code')"
		clock
		dark
		overlay
		@close="$emit('close')">
		<CheckInCode :checkInWindow="checkInWindow" :size="360" />
		<template #actions>
			<NcButton variant="secondary" @click="$emit('close')">
				{{ t('learniq', 'Back') }}
			</NcButton>
		</template>
	</CnDisplayScreen>
</template>

<script>
import { CnDisplayScreen } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import CheckInCode from './CheckInCode.vue'

export default {
	name: 'CheckInBoard',

	components: { CheckInCode, CnDisplayScreen, NcButton },

	props: {
		/** The open CheckInWindow. */
		checkInWindow: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],
}
</script>
