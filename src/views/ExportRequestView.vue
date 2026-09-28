<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExportRequestView: the three export pages (learniq#947), picked by the page
 config's `kind`:
   - audit-pack (/compliance/export): regulation and date range, downloads
     the ZIP from POST /api/compliance/audit/export;
   - course-package (/course-packages/export): course and format, downloads
     from GET /api/course-management/course-package-export; with "Share
     outside the school" on, it posts the two confirmations to
     POST /api/course-management/course-package-share instead and lists the
     sharing gate's reasons when it refuses (lesson-sharing-consent-gate);
     "Publish to the course store" posts the same confirmations to
     POST /api/store/publish (lesson-sharing-via-store-plane);
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
					v-if="!share"
					v-model="format"
					:options="packageFormats"
					:reduce="(option) => option.id"
					:clearable="false"
					:inputLabel="t('learniq', 'Format')" />
				<NcCheckboxRadioSwitch v-model="share" type="switch">
					{{ t('learniq', 'Share outside the school') }}
				</NcCheckboxRadioSwitch>
				<template v-if="share">
					<NcNoteCard type="info">
						{{
							t(
								'learniq',
								"A shared package leaves out your school's own links, file paths and access codes. Your confirmation is recorded with your name.",
							)
						}}
					</NcNoteCard>
					<NcCheckboxRadioSwitch v-model="noPupilData">
						{{
							t(
								'learniq',
								'This package holds no pupil names, photos, work or other personal data.',
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="rightsCleared">
						{{
							t(
								'learniq',
								"The school may share everything in it. Nothing comes from a publisher's method without permission.",
							)
						}}
					</NcCheckboxRadioSwitch>
				</template>
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
			<NcNoteCard v-if="blockers.length > 0" type="warning">
				<p>
					{{ t('learniq', 'This course may not leave the school yet:') }}
				</p>
				<ul class="export-request__blockers">
					<li v-for="(reason, index) in blockerTexts" :key="index">
						{{ reason }}
					</li>
				</ul>
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
				<NcButton
					v-if="kind === 'course-package' && share"
					variant="secondary"
					:disabled="busy || !ready"
					@click="publish">
					{{ t('learniq', 'Publish to the course store') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	auditPackUrl,
	coursePackagePublishUrl,
	coursePackageShareUrl,
	coursePackageUrl,
	exportJobBody,
	listRows,
	objectId,
	objectsUrl,
	oneObject,
} from '../utils/customPages.js'

export default {
	name: 'ExportRequestView',

	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect },

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
			share: false,
			noPupilData: false,
			rightsCleared: false,
			blockers: [],
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
		 * The sharing gate's reasons, one translated sentence each.
		 *
		 * @return {string[]} The sentences.
		 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
		 */
		blockerTexts() {
			return this.blockers.map((blocker) => this.blockerText(blocker))
		},

		/**
		 * @return {boolean} Whether the form is complete.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		ready() {
			if (this.kind === 'audit-pack')
				return Boolean(this.regulationSlug && this.dateFrom && this.dateTo)
			if (this.kind === 'course-package' && this.share)
				return Boolean(
					this.courseId && this.noPupilData && this.rightsCleared,
				)
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
			this.blockers = []
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
				} else if (this.kind === 'course-package' && this.share) {
					const response = await axios.post(
						generateUrl(coursePackageShareUrl()),
						{
							courseId: this.courseId,
							noPupilData: this.noPupilData,
							rightsCleared: this.rightsCleared,
						},
						{ responseType: 'blob' },
					)
					this.download(response, 'course-share.json')
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
				const body = await this.errorBody(e)
				if (Array.isArray(body.blockers) && body.blockers.length > 0) {
					this.blockers = body.blockers
				} else {
					this.error =
						(body.error ?? '')
						|| this.t('learniq', 'The export could not be made.')
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Publish the course to the course store, behind the sharing gate.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
		 */
		async publish() {
			this.busy = true
			this.error = ''
			this.done = ''
			this.blockers = []
			try {
				const response = await axios.post(
					generateUrl(coursePackagePublishUrl()),
					{
						courseId: this.courseId,
						noPupilData: this.noPupilData,
						rightsCleared: this.rightsCleared,
					},
				)
				if (response.data?.outcome === 'ok') {
					this.done = this.t('learniq', 'Published to the course store.')
				} else {
					this.error = this.publishText(response.data?.outcome)
				}
			} catch (e) {
				const body = e?.response?.data ?? {}
				if (Array.isArray(body.blockers) && body.blockers.length > 0) {
					this.blockers = body.blockers
				} else {
					this.error = this.publishText(body.outcome)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * A publish outcome other than ok, as a sentence.
		 *
		 * @param {string} outcome The server's outcome.
		 * @return {string} The sentence.
		 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
		 * @spec openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
		 */
		publishText(outcome) {
			switch (outcome) {
				case 'not_configured':
					return this.t(
						'learniq',
						'No course store is set up yet. Ask your administrator.',
					)
				case 'too_large':
					return this.t(
						'learniq',
						'This course is too large for the store.',
					)
				case 'store_unreachable':
				case 'store_rejected':
				case 'store_invalid_response':
					return this.t(
						'learniq',
						'The course store could not take the course. Try again later.',
					)
				case 'rate_limited':
					return this.t(
						'learniq',
						'The course store is busy. Try again in a few minutes.',
					)
				case 'forbidden':
					return this.t(
						'learniq',
						'You may not publish courses to the store. Your administrator decides who may.',
					)
				case 'publish_not_supported':
					return this.t(
						'learniq',
						'This server cannot publish to a course store yet. Ask your administrator to update OpenRegister.',
					)
				default:
					return this.t('learniq', 'The course could not be published.')
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
		 * Read an error body, also from a blob response.
		 *
		 * @param {Error} e The failure.
		 * @return {Promise<object>} The parsed body, or {}.
		 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
		 */
		async errorBody(e) {
			const data = e?.response?.data
			if (data instanceof Blob) {
				try {
					return JSON.parse(await data.text()) ?? {}
				} catch {
					return {}
				}
			}
			return data && typeof data === 'object' ? data : {}
		},

		/**
		 * One sharing-gate reason as a sentence in the user's language.
		 *
		 * @param {{code: string, name: string}} blocker The reason.
		 * @return {string} The sentence.
		 * @spec openspec/changes/lesson-sharing-consent-gate/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
		 */
		blockerText(blocker) {
			const name = blocker?.name ?? ''
			switch (blocker?.code) {
				case 'licence-missing':
					return this.t('learniq', 'Set a licence on the course first.')
				case 'licence-not-open':
					return this.t(
						'learniq',
						'The course licence does not allow sharing. Choose an open licence.',
					)
				case 'author-missing':
					return this.t(
						'learniq',
						'Name the author on the course, so others can credit them.',
					)
				case 'lesson-licence-not-open':
					return this.t(
						'learniq',
						'Lesson {name} has a licence that does not allow sharing.',
						{ name },
					)
				case 'material-licence-not-open':
					return this.t(
						'learniq',
						'Material {name} has a licence that does not allow sharing.',
						{ name },
					)
				case 'pupil-data-not-confirmed':
					return this.t(
						'learniq',
						'Confirm that the package holds no pupil data.',
					)
				case 'rights-not-confirmed':
					return this.t(
						'learniq',
						'Confirm that the school may share everything in it.',
					)
				default:
					return blocker?.code ?? ''
			}
		},
	},
}
</script>

<style scoped>
.export-request {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-inline-size: 40rem;
}

.export-request__blockers {
	margin-block: 0;
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 5);
}

.export-request__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
