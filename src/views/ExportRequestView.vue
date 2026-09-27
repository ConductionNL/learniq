<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExportRequestView: the three export pages (learniq#947), picked by the page
 config's `kind`:
   - audit-pack (/compliance/export): regulation and date range, downloads
     the ZIP from POST /api/compliance/audit/export;
   - course-package (/course-packages/export): course and format, downloads
     from GET /api/course-management/course-package-export;
   - data-exchange (/data-exchange/request): creates a queued export
     DataExchangeJob for a named connection and scope, then links to it.

 @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
-->
<template>
	<div class="export-request">
		<h2>{{ heading }}</h2>
		<form class="export-request__form" @submit.prevent="submit">
			<template v-if="kind === 'audit-pack'">
				<NcSelect
					v-model="regulationSlug"
					:options="regulationOptions"
					:reduce="(option) => option.id"
					:inputLabel="t('learniq', 'Regulation')" />
				<label for="er-from">{{ t('learniq', 'From') }}</label>
				<input id="er-from" v-model="dateFrom" type="date" required />
				<label for="er-to">{{ t('learniq', 'Until') }}</label>
				<input id="er-to" v-model="dateTo" type="date" required />
			</template>

			<template v-else-if="kind === 'course-package'">
				<NcSelect
					v-model="courseId"
					:options="courseOptions"
					:reduce="(option) => option.id"
					:inputLabel="t('learniq', 'Course')" />
				<NcSelect
					v-model="format"
					:options="packageFormats"
					:reduce="(option) => option.id"
					:clearable="false"
					:inputLabel="t('learniq', 'Format')" />
			</template>

			<template v-else>
				<NcSelect
					v-model="target"
					:options="targetOptions"
					:reduce="(option) => option.id"
					:clearable="false"
					:inputLabel="t('learniq', 'Send to')" />
				<NcSelect
					v-model="courseId"
					:options="courseOptions"
					:reduce="(option) => option.id"
					:inputLabel="t('learniq', 'Course (optional)')" />
				<label for="er-learner">{{
					t('learniq', 'Learner user id (optional)')
				}}</label>
				<input id="er-learner" v-model="learnerId" type="text" />
				<label for="er-format">{{
					t('learniq', 'Format hint (optional)')
				}}</label>
				<input id="er-format" v-model="formatHint" type="text" />
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="done" type="success">
				{{ done }}
			</NcNoteCard>
			<p v-if="jobId">
				<router-link
					:to="{ name: 'DataExchangeJobDetail', params: { id: jobId } }">
					{{ t('learniq', 'Open the export job') }}
				</router-link>
			</p>

			<div class="export-request__actions">
				<NcButton type="submit" variant="primary" :disabled="busy || !ready">
					{{
						kind === 'data-exchange'
							? t('learniq', 'Request the export')
							: t('learniq', 'Download')
					}}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	auditPackUrl,
	coursePackageUrl,
	exportJobBody,
	listRows,
	objectId,
	objectsUrl,
	oneObject,
} from '../utils/customPages.js'

export default {
	name: 'ExportRequestView',

	components: { NcButton, NcNoteCard, NcSelect },

	props: {
		/** 'audit-pack', 'course-package' or 'data-exchange' (page config). */
		kind: { type: String, default: 'data-exchange' },
	},

	data() {
		return {
			regulationSlug: null,
			regulationOptions: [],
			dateFrom: '',
			dateTo: '',
			courseId: null,
			courseOptions: [],
			format: 'common-cartridge',
			target: 'bron-rod',
			learnerId: '',
			formatHint: '',
			busy: false,
			error: '',
			done: '',
			jobId: '',
		}
	},

	computed: {
		/**
		 * @return {string} The heading for this kind.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		heading() {
			return (
				{
					'audit-pack': this.t('learniq', 'Export an audit pack'),
					'course-package': this.t('learniq', 'Export a course package'),
				}[this.kind] ?? this.t('learniq', 'Request a data export')
			)
		},

		/**
		 * @return {Array<{id: string, label: string}>} Course package formats.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		packageFormats() {
			return [
				{
					id: 'common-cartridge',
					label: this.t('learniq', 'Common Cartridge'),
				},
				{ id: 'scholiq-json', label: this.t('learniq', 'Learniq JSON') },
			]
		},

		/**
		 * @return {Array<{id: string, label: string}>} Named connections an export can go to.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		targetOptions() {
			return [
				{ id: 'bron-rod', label: this.t('learniq', 'DUO (BRON/ROD)') },
				{ id: 'oso', label: this.t('learniq', 'OSO transfer file') },
				{
					id: 'leerplicht',
					label: this.t('learniq', 'Compulsory education office'),
				},
				{
					id: 'swv',
					label: this.t('learniq', 'Regional partnership (SWV)'),
				},
				{ id: 'hr', label: this.t('learniq', 'HR system') },
			]
		},

		/**
		 * @return {boolean} Whether the form is complete.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		ready() {
			if (this.kind === 'audit-pack')
				return Boolean(this.regulationSlug && this.dateFrom && this.dateTo)
			if (this.kind === 'course-package')
				return Boolean(this.courseId && this.format)
			return Boolean(this.target)
		},
	},

	async mounted() {
		try {
			if (this.kind === 'audit-pack') {
				const regs = listRows(
					(
						await axios.get(generateUrl(objectsUrl('regulation')), {
							params: { _limit: 500 },
						})
					).data,
				)
				this.regulationOptions = regs
					.filter((r) => r.slug)
					.map((r) => ({ id: r.slug, label: r.name || r.slug }))
			} else {
				const courses = listRows(
					(
						await axios.get(generateUrl(objectsUrl('course')), {
							params: { _limit: 500 },
						})
					).data,
				)
				this.courseOptions = courses.map((c) => ({
					id: objectId(c),
					label: c.name || c.code || objectId(c),
				}))
			}
		} catch {
			this.error = this.t('learniq', 'The choices could not be loaded.')
		}
	},

	methods: {
		/**
		 * Download or request, depending on the kind.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async submit() {
			this.busy = true
			this.error = ''
			this.done = ''
			this.jobId = ''
			try {
				if (this.kind === 'audit-pack') {
					const response = await axios.post(
						generateUrl(
							auditPackUrl(
								this.regulationSlug,
								this.dateFrom,
								this.dateTo,
							),
						),
						{},
						{ responseType: 'blob' },
					)
					this.download(response, 'audit-pack.zip')
				} else if (this.kind === 'course-package') {
					const response = await axios.get(
						generateUrl(coursePackageUrl(this.courseId, this.format)),
						{
							responseType: 'blob',
						},
					)
					this.download(response, 'course-package.zip')
				} else {
					const created = oneObject(
						(
							await axios.post(
								generateUrl(objectsUrl('data-exchange-job')),
								exportJobBody({
									target: this.target,
									format: this.formatHint.trim(),
									filters: {
										courseId: this.courseId,
										learnerId: this.learnerId.trim(),
									},
									requestedBy: getCurrentUser()?.uid ?? '',
									requestedAt: new Date().toISOString(),
									tenantId: '',
								}),
							)
						).data,
					)
					this.jobId = objectId(created)
					this.done = this.t('learniq', 'The export is queued.')
				}
			} catch (e) {
				this.error =
					(await this.errorText(e))
					|| this.t('learniq', 'The export could not be made.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Save a blob response under the server's file name.
		 *
		 * @param {object} response The axios blob response.
		 * @param {string} fallback File name when the server names none.
		 * @return {void}
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		download(response, fallback) {
			const disposition = response.headers?.['content-disposition'] ?? ''
			const match = /filename="?([^";]+)"?/.exec(disposition)
			const url = URL.createObjectURL(response.data)
			const link = document.createElement('a')
			link.href = url
			link.download = match ? match[1] : fallback
			link.click()
			URL.revokeObjectURL(url)
			this.done = this.t('learniq', 'The download has started.')
		},

		/**
		 * Read an error message, also from a blob error body.
		 *
		 * @param {Error} e The failure.
		 * @return {Promise<string>} The message, or ''.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		async errorText(e) {
			const data = e?.response?.data
			if (data instanceof Blob) {
				try {
					return JSON.parse(await data.text()).error ?? ''
				} catch {
					return ''
				}
			}
			return data?.error ?? ''
		},
	},
}
</script>

<style scoped>
.export-request {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
}

.export-request__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
