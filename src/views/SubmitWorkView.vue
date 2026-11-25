<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SubmitWorkView: hand in work for an assignment
 (route /assignments/:assignmentId/submit, learniq#947).

 Opens CnRichSubmitDialog with the assignment's late rule. On confirm it
 creates a draft Submission for the current learner, attaches the picked
 files (from the device, or from her own Nextcloud files through the
 dialog's file-sources slot, chooseFromMyFiles) to it through OpenRegister's
 file API, records their references in
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
			@close="leave">
			<template #file-sources="{ addFiles }">
				<NcButton class="submit-work__my-files" @click="chooseFromMyFiles(addFiles)">
					{{ t('learniq', 'Choose from my files') }}
				</NcButton>
				<NcNoteCard v-if="pickError" type="error">
					{{ pickError }}
				</NcNoteCard>
			</template>
		</CnRichSubmitDialog>
	</div>
</template>

<script>
import { CnRichSubmitDialog } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	handInAction,
	objectId,
	objectsUrl,
	oneObject,
	transitionUrl,
} from '../utils/customPages.js'
import { filesFromMyFiles } from '../utils/myFiles.js'
import { handInLearners } from '../utils/workGroups.js'

export default {
	name: 'SubmitWorkView',

	components: { CnRichSubmitDialog, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** Assignment UUID from the route. */
		assignmentId: { type: String, required: true },
	},

	data() {
		return { loading: true, loadError: '', assignment: {}, pickError: '' }
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

	/**
	 * Load the assignment the learner hands work in for.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
	 */
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
		 * Pick files from the learner's own Nextcloud files and add them to
		 * the hand-in: the picker gives paths, which are read over WebDAV in
		 * her own session and handed to the dialog as files, so they are
		 * attached like a file from the device.
		 *
		 * @param {(files: File[]) => boolean} addFiles The dialog's `addFiles` (its `file-sources` slot).
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files
		 */
		async chooseFromMyFiles(addFiles) {
			this.pickError = ''
			let paths
			try {
				paths = await getFilePickerBuilder(this.t('learniq', 'Choose your work'))
					.setMultiSelect(true)
					.allowDirectories(false)
					.addButton({ label: this.t('learniq', 'Add to my hand-in'), type: 'primary', callback: () => {} })
					.build()
					.pick()
			} catch {
				// Closing the picker is not an error the learner needs to see.
				return
			}

			try {
				const files = await filesFromMyFiles(paths, {
					http: axios,
					remoteBase: generateRemoteUrl('dav'),
					uid: getCurrentUser()?.uid ?? '',
				})
				addFiles(files)
			} catch {
				this.pickError = this.t('learniq', 'This file could not be read from your files.')
			}
		},

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
				const learnerIds = await this.handInLearnerIds(learnerId)
				const created = oneObject(
					(
						await axios.post(generateUrl(objectsUrl('submission')), {
							assignmentId: this.assignmentId,
							learnerIds,
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
		 * The learners of this hand-in: the caller's whole work group when
		 * the assignment is a group hand-in that names a work group set,
		 * else only the caller (enrolment-self-join-work-group).
		 *
		 * @param {string} learnerId The caller's user id.
		 * @return {Promise<string[]>} The learnerIds.
		 * @spec openspec/specs/enrolment/spec.md#requirement-a-group-hand-in-names-the-whole-work-group
		 */
		async handInLearnerIds(learnerId) {
			if (
				!this.assignment.groupSubmission
				|| !this.assignment.workGroupSetName
			) {
				return [learnerId]
			}
			try {
				const sets =
					(
						await axios.get(
							generateUrl('/apps/learniq/api/my/work-groups'),
						)
					).data.sets ?? []
				return handInLearners(this.assignment, sets, learnerId)
			} catch {
				return [learnerId]
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
