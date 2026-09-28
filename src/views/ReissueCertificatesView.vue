<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ReissueCertificatesView: an HR or compliance officer reissues every
 certificate of a course (route /courses/:courseId/reissue,
 credentials-bulk-reissue).

 Shows how many issued certificates the run will touch and how many revoked
 or expired ones it leaves alone, takes the required reason and queues the
 run through learniq's POST /api/courses/{id}/credentials/reissue. The summary
 of the last run shows when it is done.

 @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
-->
<template>
	<div class="reissue-certificates">
		<h2>{{ t('learniq', 'Reissue certificates') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<form v-else class="reissue-certificates__form" @submit.prevent="start">
			<p>
				{{
					n(
						'learniq',
						'%n issued certificate will be rebuilt and signed again.',
						'%n issued certificates will be rebuilt and signed again.',
						counts.issued,
					)
				}}
			</p>
			<p class="reissue-certificates__meta">
				{{
					t(
						'learniq',
						'{revoked} revoked and {expired} expired certificates are left as they are. Learners keep their certificate number, issue date and expiry.',
						{ revoked: counts.revoked, expired: counts.expired },
					)
				}}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('learniq', 'Reason for the reissue')"
				:helperText="
					t(
						'learniq',
						'Stored on every certificate and shown in its history.',
					)
				"
				required />
			<NcNoteCard v-if="lastRun" type="info">
				{{ lastRunText }}
			</NcNoteCard>
			<NcNoteCard v-if="queued" type="success">
				{{
					t(
						'learniq',
						'The reissue has started. Learners get a notification when their certificate is ready.',
					)
				}}
			</NcNoteCard>
			<div class="reissue-certificates__actions">
				<NcButton
					type="submit"
					variant="primary"
					:disabled="busy || reason.trim() === '' || counts.issued === 0">
					{{ t('learniq', 'Reissue certificates') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'ReissueCertificatesView',

	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea },

	props: {
		/** Course UUID from the route. */
		courseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			counts: { issued: 0, revoked: 0, expired: 0 },
			lastRun: null,
			reason: '',
			busy: false,
			queued: false,
		}
	},

	computed: {
		/**
		 * @return {string} The last run in words.
		 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
		 */
		lastRunText() {
			if (!this.lastRun || this.lastRun.status === 'queued') {
				return this.t('learniq', 'A reissue is waiting to run.')
			}
			return this.t(
				'learniq',
				'Last reissue: {processed} reissued, {skipped} skipped, {failed} failed.',
				{
					processed: this.lastRun.processed ?? 0,
					skipped: this.lastRun.skipped ?? 0,
					failed: this.lastRun.failed ?? 0,
				},
			)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the counts and the last run.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
		 */
		async load() {
			try {
				const data = (
					await axios.get(
						generateUrl(
							`/apps/learniq/api/courses/${this.courseId}/credentials/reissue`,
						),
					)
				).data
				this.counts = {
					issued: data.issued ?? 0,
					revoked: data.revoked ?? 0,
					expired: data.expired ?? 0,
				}
				this.lastRun = data.lastRun ?? null
			} catch {
				this.error = this.t(
					'learniq',
					'Only HR and compliance officers can reissue certificates.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Queue the reissue.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/credentials-bulk-reissue/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
		 */
		async start() {
			this.busy = true
			try {
				await axios.post(
					generateUrl(
						`/apps/learniq/api/courses/${this.courseId}/credentials/reissue`,
					),
					{ reason: this.reason.trim() },
				)
				this.queued = true
				this.lastRun = { status: 'queued' }
			} catch {
				this.error = this.t('learniq', 'The reissue could not be started.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.reissue-certificates {
	max-width: 720px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.reissue-certificates__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.reissue-certificates__meta {
	color: var(--color-text-maxcontrast);
}

.reissue-certificates__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
