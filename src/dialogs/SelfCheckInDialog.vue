<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SelfCheckInDialog (attendance-self-check-in): the lesson page's "Open self
 check-in" header action opens it with the lesson's uuid. It holds the
 SelfCheckInPanel, which opens the window and shows the code on the board.

 @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Self check-in')"
		size="large"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<SelfCheckInPanel :sessionId="sessionId" />
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import SelfCheckInPanel from '../components/SelfCheckInPanel.vue'

export default {
	name: 'SelfCheckInDialog',

	components: { NcDialog, SelfCheckInPanel },

	props: {
		/** The uuid of the lesson (Session). */
		sessionId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
}
</script>
