<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubmitWorkView: hand in work for an assignment
 (route /assignments/:assignmentId/submit, learniq#947).

 Opens CnRichSubmitDialog with the assignment's late rule. On confirm it
 creates a draft Submission for the current learner, attaches the picked
 files to it through OpenRegister's file API, records their references in
 attachmentRefs, and fires `submit`, or `submitLate` once the deadline of an
 assignment that accepts late work has passed; SubmissionWindowGuard refuses
 the wrong one (learniq#983). Closing the dialog returns to the assignment.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="submit-work">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>
		<CnRichSubmitDialog
			v-else
			ref="dialog"
			:dialogTitle="
				t('learniq', 'Hand in: {title}', { title: assignment.title })
			"
			:description="assignment.instructions || ''"
			:showFiles="true"
			:filesRequired="true"
			:filesLabel="t('learniq', 'Your work')"
			:showNotes="false"
			:lateWarning="lateWarning"
			:confirmLabel="t('learniq', 'Hand in')"
			:cancelLabel="t('learniq', 'Cancel')"
			:closeLabel="t('learniq', 'Close')"
			:successText="t('learniq', 'Your work is handed in.')"
			@confirm="submit"
			@close="leave" />
	</div>
</template>

<script>
import { CnRichSubmitDialog } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	handInAction,
	objectId,
	objectsUrl,
	oneObject,
	transitionUrl,
} from '../utils/customPages.js'

export default {
	name: 'SubmitWorkView',

	components: { CnRichSubmitDialog, NcLoadingIcon, NcNoteCard },

	props: {
		/** Assignment UUID from the route. */
		assignmentId: { type: String, required: true },
	},

	data() {
		return { loading: true, loadError: '', assignment: {} }
	},

	computed: {
		/**
		 * @return {string} A warning when the deadline has passed, '' otherwise.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		lateWarning() {
			const due = this.assignment.dueAt
				? new Date(this.assignment.dueAt)
				: null
			if (!due || due > new Date()) return ''
			if (this.assignment.allowLateSubmission === false) {
				return this.t(
					'learniq',
					'The deadline has passed and this assignment does not accept late work.',
				)
			}
			return this.t(
				'learniq',
				'The deadline has passed. Your work will be marked as late.',
			)
		},
	},

	async mounted() {
		try {
			this.assignment = oneObject(
				(
					await axios.get(
						generateUrl(objectsUrl('assignment', this.assignmentId)),
					)
				).data,
			)
		} catch {
			this.loadError = this.t(
				'learniq',
				'This assignment could not be loaded.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Create the submission, attach the files and submit it.
		 *
		 * @param {{files: File[]}} payload The dialog's form data.
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async submit({ files }) {
			try {
				const learnerId = getCurrentUser()?.uid ?? ''
				const created = oneObject(
					(
						await axios.post(generateUrl(objectsUrl('submission')), {
							assignmentId: this.assignmentId,
							learnerIds: [learnerId],
							tenant_id: this.assignment.tenant_id ?? '',
						})
					).data,
				)
				const id = objectId(created)
				const refs = await this.upload(id, files)
				await axios.put(generateUrl(objectsUrl('submission', id)), {
					...created,
					attachmentRefs: refs,
				})
				await axios.post(generateUrl(transitionUrl(id)), {
					action: handInAction(this.assignment),
				})
				this.$refs.dialog.setResult({ success: true })
			} catch (e) {
				this.$refs.dialog.setResult({
					error:
						e?.response?.data?.error
						|| this.t('learniq', 'Your work could not be handed in.'),
				})
			}
		},

		/**
		 * Attach files to the submission; returns their references.
		 *
		 * @param {string} id Submission UUID.
		 * @param {File[]} files Picked files.
		 * @return {Promise<string[]>} File references.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async upload(id, files) {
			if (!files || files.length === 0) return []
			const form = new FormData()
			files.forEach((file) => form.append('files[]', file))
			const response = await axios.post(
				generateUrl(objectsUrl('submission', id) + '/filesMultipart'),
				form,
			)
			const stored = Array.isArray(response.data)
				? response.data
				: (response.data?.results ?? [])
			return stored
				.map((f) => String(f.path ?? f.id ?? f.title ?? ''))
				.filter(Boolean)
		},

		/**
		 * Back to the assignment.
		 *
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		leave() {
			this.$router
				.push({
					name: 'AssignmentDetail',
					params: { id: this.assignmentId },
				})
				.catch(() => {})
		},
	},
}
</script>
