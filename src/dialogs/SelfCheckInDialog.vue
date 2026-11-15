<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 SelfCheckInDialog (attendance-self-check-in): the lesson page's "Open self
 check-in" header action opens it with the lesson's uuid. It holds the
 SelfCheckInPanel, which opens the window, and swaps itself for CheckInBoard
 while the code shows full screen. The dialog closes while the board shows,
 so the two never trap focus or Escape against each other; the open window
 is kept here across the switch.
 @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
-->
<template>
	<CheckInBoard
		v-if="onBoard && checkInWindow"
		:checkInWindow="checkInWindow"
		@close="onBoard = false" />
	<NcDialog
		v-else
		:open="true"
		:name="t('learniq', 'Self check-in')"
		size="large"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<SelfCheckInPanel
			v-model:checkInWindow="checkInWindow"
			:sessionId="sessionId"
			@board="onBoard = true" />
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import CheckInBoard from '../components/CheckInBoard.vue'
import SelfCheckInPanel from '../components/SelfCheckInPanel.vue'

export default {
	name: 'SelfCheckInDialog',

	components: { CheckInBoard, NcDialog, SelfCheckInPanel },

	props: {
		/** The uuid of the lesson (Session). */
		sessionId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			checkInWindow: null,
			onBoard: false,
		}
	},
}
</script>
