<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LessonAssistNoticeDialog: lesson-ai-assist-actions.

 Shown once per browser before the first AI assist call from the lesson
 composer. It says what leaves learniq (the lesson text and goal titles) and
 that the text must not contain pupil data, and the call goes out only after
 the teacher chooses to continue. LessonAssistPanel owns when it opens and
 remembers the confirmation.

 @spec openspec/specs/course-management/spec.md#scenario-the-first-assist-call-asks-for-confirmation
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Your text goes to an AI model')"
		@update:open="
			(v) => {
				if (!v) $emit('cancel')
			}
		">
		<div class="lesson-assist-notice">
			<p>
				{{
					t(
						'learniq',
						'The AI help sends the lesson text and the goal titles to the AI model your school set up in Hermiq.',
					)
				}}
			</p>
			<p>
				<strong>{{
					t(
						'learniq',
						'Do not put pupil names or other pupil data in the lesson text.',
					)
				}}</strong>
			</p>
			<p>
				{{
					t(
						'learniq',
						'Every answer comes back as a draft. You check it, edit it and decide whether to keep it.',
					)
				}}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('learniq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('confirm')">
				{{ t('learniq', 'Continue') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'LessonAssistNoticeDialog',

	components: {
		NcButton,
		NcDialog,
	},

	emits: ['confirm', 'cancel'],
}
</script>

<style scoped>
.lesson-assist-notice {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
