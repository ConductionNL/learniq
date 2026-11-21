<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 ConcernReportDialog: "Zorg melden" (learniq/LqZorgMelden, LqLlZorgMelden,
 LqOuZorgMelden). A member of staff files it from a pupil's page (`learnerId`
 set), a pupil or a guardian from their own start page. It creates one
 ConcernReport that only the confidential counsellors and the reporter can
 read. The wording follows who files it: "u" for staff and guardians, "je"
 for the pupil.
 @spec openspec/specs/confidential-counsel/spec.md
-->
<template>
	<CnDialog
		:open="true"
		:name="t('learniq', 'Report a concern')"
		:eyebrow="t('learniq', 'Confidential')"
		:subtitle="subtitle"
		width="form"
		:noClose="busy"
		v-bind="noticeProps"
		data-testid="concern-report-dialog"
		@closing="$emit('close')">
		<div class="concern-report">
			<div v-if="audience === 'staff'" class="concern-report__learner">
				<span class="concern-report__label">{{ t('learniq', 'About which pupil') }}</span>
				<strong>{{ learnerName || learnerId }}</strong>
				<span class="concern-report__muted">
					{{ learnerLine }}
				</span>
			</div>
			<CnChoiceCards
				v-model="topic"
				:label="t('learniq', 'What is it about')"
				:options="topicOptions"
				:disabled="busy"
				data-testid="concern-report-topic" />
			<NcTextArea
				v-model="description"
				:label="t('learniq', 'What happened')"
				:helperText="descriptionHelp"
				:disabled="busy"
				rows="4"
				data-testid="concern-report-description" />
			<NcTextField
				v-model="happenedOn"
				type="date"
				:label="t('learniq', 'When (optional)')"
				:helperText="t('learniq', 'Leave it empty when it keeps happening.')"
				:disabled="busy"
				data-testid="concern-report-date" />
			<CnChoiceCards
				v-model="conversation"
				:label="t('learniq', 'A conversation')"
				:hideLabel="true"
				:multiple="true"
				layout="rows"
				:options="conversationOptions"
				:disabled="busy"
				data-testid="concern-report-conversation" />
			<p v-if="audience !== 'staff'" class="concern-report__muted">
				{{ followUpLine }}
			</p>
			<DialogNoticeFallback :notice="notice" />
		</div>
		<template #actions>
			<CnDialogActions
				:saving="busy"
				:canSubmit="ready"
				:confirmLabel="t('learniq', 'Send report')"
				:cancelLabel="t('learniq', 'Cancel')"
				@cancel="$emit('close')"
				@submit="send" />
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { NcTextArea, NcTextField } from '@nextcloud/vue'
import DialogNoticeFallback from './DialogNoticeFallback.vue'
import { profileName } from '../utils/learnerName.js'
import {
	canSendConcern,
	CONCERN_TOPICS,
	concernAudience,
	concernReportBody,
} from './dialogBodies.js'
import { dialogNoticeProps, requestRefusal } from './dialogNotice.js'
import { announceSaved, createObject, readObject } from './dialogRequests.js'

export default {
	name: 'ConcernReportDialog',

	components: { CnChoiceCards, CnDialog, CnDialogActions, DialogNoticeFallback, NcTextArea, NcTextField },

	props: {
		/** The pupil's learner profile uuid, when staff file from the pupil's page. */
		learnerId: { type: String, default: '' },
		/** Who files it: `staff`, `guardian` or `learner`; unset follows the pupil id and the user's role. */
		audience: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			topic: 'bullying',
			description: '',
			happenedOn: '',
			conversation: ['yes'],
			learnerName: '',
			learnerGroup: '',
			busy: false,
			notice: null,
		}
	},

	computed: {
		/**
		 * @return {string} `staff`, `guardian` or `learner`.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		who() {
			return this.audience || concernAudience(this.learnerId, loadState('learniq', 'primaryRole', 'learner'))
		},

		/**
		 * @return {string} The sentence under the title: who can read the report.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		subtitle() {
			if (this.who === 'staff') {
				return this.t('learniq', 'Your report goes to the confidential counsellor. Only the counsellor and you can read it, not {name} or the parents either.', { name: this.learnerName || this.t('learniq', 'the pupil') })
			}
			if (this.who === 'guardian') {
				return this.t('learniq', 'Your report goes to the school\'s confidential counsellor. Only the counsellor and you can read it. The mentor does not see it.')
			}
			return this.t('learniq', 'Your report goes to the school\'s confidential counsellor. Only the counsellor and you can read it. Your mentor and your parents do not see it.')
		},

		/**
		 * @return {string} The pupil's group and where the pupil came from.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		learnerLine() {
			const from = this.t('learniq', 'filled in from the pupil\'s page')
			return this.learnerGroup ? `${this.learnerGroup} · ${from}` : from
		},

		/**
		 * @return {object[]} The topics as choice cards.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		topicOptions() {
			const labels = {
				bullying: this.t('learniq', 'Bullying'),
				harassment: this.t('learniq', 'Intimidation'),
				discrimination: this.t('learniq', 'Discrimination'),
				violence: this.t('learniq', 'Violence'),
				other: this.t('learniq', 'Something else'),
			}
			return CONCERN_TOPICS.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * @return {string} The help under "What happened", in the reporter's form of address.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		descriptionHelp() {
			return this.who === 'learner'
				? this.t('learniq', 'Tell what happened, where, and who was there if you like. The counsellor reads your own words.')
				: this.t('learniq', 'Tell what happened, where and who were there if you want to. The confidential counsellor reads your own words.')
		},

		/**
		 * @return {object[]} The one checkbox card for a conversation.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		conversationOptions() {
			const descriptions = {
				staff: this.t('learniq', 'Untick it if you only want to let them know.'),
				guardian: this.t('learniq', 'The counsellor contacts you. Untick it if you only want to let them know.'),
				learner: this.t('learniq', 'The counsellor gets in touch with you. Untick it if you only want to let them know.'),
			}
			return [{
				value: 'yes',
				label: this.t('learniq', 'I would like a conversation with the confidential counsellor'),
				description: descriptions[this.who],
			}]
		},

		/**
		 * @return {string} What the reporter sees after sending.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		followUpLine() {
			return this.who === 'learner'
				? this.t('learniq', 'After sending you see whether your report was received, is being handled or is closed.')
				: this.t('learniq', 'After sending you see whether your report is received, in progress or closed.')
		},

		/**
		 * @return {object} The fields as the body builder reads them.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		form() {
			return {
				topic: this.topic,
				description: this.description,
				happenedOn: this.happenedOn,
				wantsConversation: this.conversation.includes('yes'),
				learnerId: this.who === 'staff' ? this.learnerId : '',
			}
		},

		/**
		 * @return {boolean} Whether the report can be sent.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		ready() {
			return canSendConcern(this.form)
		},

		/**
		 * @return {object} The CnDialog notice props.
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		noticeProps() {
			return dialogNoticeProps(this.notice)
		},
	},

	/**
	 * Name the pupil a member of staff reports about.
	 *
	 * @spec openspec/specs/confidential-counsel/spec.md
	 */
	async created() {
		if (this.who !== 'staff' || !this.learnerId) {
			return
		}
		try {
			const profile = await readObject('learner-profile', this.learnerId)
			this.learnerName = profileName(profile)
			this.learnerGroup = profile?.groupLabel ?? ''
		} catch {
			// The id stays on the card; the report does not depend on the name.
		}
	},

	methods: {
		/**
		 * Create the report and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/confidential-counsel/spec.md
		 */
		async send() {
			this.busy = true
			this.notice = null
			try {
				const report = await createObject('concern-report', concernReportBody(this.form))
				announceSaved('learniq:concern-report:sent', { reportId: report?.id ?? '' })
				this.$emit('close')
			} catch (error) {
				this.notice = requestRefusal(error, this.t('learniq', 'Not sent.'), this.t('learniq', 'The report could not be sent. Try again later.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.concern-report {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.concern-report__learner {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.concern-report__label,
.concern-report__muted {
	color: var(--color-text-maxcontrast);
}
</style>
