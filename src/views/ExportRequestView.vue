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
	<div class="export-request lq-board-page">
		<CnPageHeader :title="heading" />
		<CnDetailCard class="export-request__card">
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
					<label for="er-learner">{{
						t('learniq', 'Learner user id (optional)')
					}}</label>
					<input id="er-learner" v-model="learnerId" type="text" />
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
						v-if="kind === 'course-package' && share && canPublish"
						variant="secondary"
						:disabled="busy || !ready"
						@click="publish">
						{{ t('learniq', 'Publish to the course marketplace') }}
					</NcButton>
				</div>
			</form>
		</CnDetailCard>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
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
	exchangeRequestBody,
	exchangeRequestUrl,
	listRows,
	objectId,
	objectsUrl,
} from '../utils/customPages.js'
import { blockerText, publishText } from '../utils/exportOutcomeTexts.js'
import { normaliseStoreAccess } from '../utils/storeAccess.js'

export default {
	name: 'ExportRequestView',

	components: { CnDetailCard, CnPageHeader, NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect },

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
			busy: false,
			error: '',
			done: '',
			jobId: '',
			// store-rights-for-teachers (D27): the publish button shows only
			// to someone the matrix and the store plane admit; the endpoint
			// checks the same rights.
			canPublish: normaliseStoreAccess(
				loadState('learniq', 'storeAccess', null),
			).publish,
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
				{ id: 'uwlr', label: this.t('learniq', 'Test supplier (UWLR)') },
				{ id: 'edu-v', label: this.t('learniq', 'Edu-V') },
				{ id: 'basispoort', label: this.t('learniq', 'Basispoort') },
				{ id: 'hr', label: this.t('learniq', 'HR system') },
			]
		},

		/**
		 * The sharing gate's reasons, one translated sentence each.
		 *
		 * @return {string[]} The sentences.
		 * @spec openspec/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
		 */
		blockerTexts() {
			return this.blockers.map((blocker) => blockerText(blocker))
		},

		/**
		 * @return {boolean} Whether the form is complete.
		 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
		 */
		ready() {
			if (this.kind === 'audit-pack') {
				return this.auditPackReady()
			}
			if (this.kind === 'course-package') {
				return this.coursePackageReady()
			}
			return Boolean(this.target)
		},
	},

	/**
	 * Load the choices the export form offers for its kind.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
	 */
	async mounted() {
		try {
			if (this.kind === 'audit-pack') {
				this.regulationOptions = await this.loadRegulationOptions()
			} else {
				this.courseOptions = await this.loadCourseOptions()
			}
		} catch {
			this.error = this.t('learniq', 'The choices could not be loaded.')
		}
	},

	methods: {
		/**
		 * @return {boolean} Whether the audit pack has its regulation and period.
		 */
		auditPackReady() {
			return Boolean(this.regulationSlug && this.dateFrom && this.dateTo)
		},

		/**
		 * @return {boolean} Whether a course package can be made: a course and
		 *     both confirmations to share it, else a course and a format.
		 */
		coursePackageReady() {
			if (this.share) {
				return Boolean(
					this.courseId && this.noPupilData && this.rightsCleared,
				)
			}
			return Boolean(this.courseId && this.format)
		},

		/**
		 * Up to 500 objects of one schema, as rows.
		 *
		 * @param {string} schema The schema slug.
		 * @return {Promise<Array<object>>} The rows.
		 */
		async fetchRows(schema) {
			return listRows(
				(
					await axios.get(generateUrl(objectsUrl(schema)), {
						params: { _limit: 500 },
					})
				).data,
			)
		},

		/**
		 * The regulations an audit pack can cover, by slug.
		 *
		 * @return {Promise<Array<{id: string, label: string}>>} The options.
		 */
		async loadRegulationOptions() {
			const regs = await this.fetchRows('regulation')
			return regs
				.filter((r) => r.slug)
				.map((r) => ({ id: r.slug, label: r.name || r.slug }))
		},

		/**
		 * The courses a package or share can be made of.
		 *
		 * @return {Promise<Array<{id: string, label: string}>>} The options.
		 */
		async loadCourseOptions() {
			const courses = await this.fetchRows('course')
			return courses.map((c) => ({
				id: objectId(c),
				label: c.name || c.code || objectId(c),
			}))
		},

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
				await this.runExport()
			} catch (e) {
				this.showExportFailure(await this.errorBody(e))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Run the export this page's kind makes.
		 *
		 * @return {Promise<void>}
		 */
		runExport() {
			if (this.kind === 'audit-pack') {
				return this.downloadAuditPack()
			}
			if (this.kind === 'course-package' && this.share) {
				return this.downloadCourseShare()
			}
			if (this.kind === 'course-package') {
				return this.downloadCoursePackage()
			}
			return this.requestExchange()
		},

		/**
		 * Download the audit pack for the chosen regulation and period.
		 *
		 * @return {Promise<void>}
		 */
		async downloadAuditPack() {
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
		},

		/**
		 * Download the course as a share package, behind the sharing gate.
		 *
		 * @return {Promise<void>}
		 */
		async downloadCourseShare() {
			const response = await axios.post(
				generateUrl(coursePackageShareUrl()),
				this.shareConfirmations(),
				{ responseType: 'blob' },
			)
			this.download(response, 'course-share.json')
		},

		/**
		 * Download the course package in the chosen format.
		 *
		 * @return {Promise<void>}
		 */
		async downloadCoursePackage() {
			const response = await axios.get(
				generateUrl(coursePackageUrl(this.courseId, this.format)),
				{
					responseType: 'blob',
				},
			)
			this.download(response, 'course-package.zip')
		},

		/**
		 * Hand a data export to the chosen connection.
		 *
		 * @return {Promise<void>}
		 */
		async requestExchange() {
			const response = await axios.post(
				generateUrl(exchangeRequestUrl()),
				exchangeRequestBody({
					target: this.target,
					learnerId: this.learnerId,
				}),
			)
			this.jobId = response.data?.jobId ?? ''
			this.done = this.t(
				'learniq',
				"Integriq has the export. It runs once learniq's checks allow it.",
			)
		},

		/**
		 * The course and the two sharing confirmations, as the share and
		 * publish endpoints take them.
		 *
		 * @return {{courseId: string, noPupilData: boolean, rightsCleared: boolean}} The body.
		 */
		shareConfirmations() {
			return {
				courseId: this.courseId,
				noPupilData: this.noPupilData,
				rightsCleared: this.rightsCleared,
			}
		},

		/**
		 * Show a failed export: the sharing gate's reasons, else the server's
		 * error or a general sentence.
		 *
		 * @param {object} body The error body.
		 * @return {void}
		 */
		showExportFailure(body) {
			if (!this.takeBlockers(body)) {
				this.error =
					(body.error ?? '')
					|| this.t('learniq', 'The export could not be made.')
			}
		},

		/**
		 * Show the sharing gate's reasons when the error body carries any.
		 *
		 * @param {object} body The error body.
		 * @return {boolean} Whether there were reasons to show.
		 */
		takeBlockers(body) {
			if (Array.isArray(body.blockers) && body.blockers.length > 0) {
				this.blockers = body.blockers
				return true
			}
			return false
		},

		/**
		 * Publish the course to the course store, behind the sharing gate.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
		 */
		async publish() {
			this.busy = true
			this.error = ''
			this.done = ''
			this.blockers = []
			try {
				const response = await this.sendPublish()
				this.showPublishOutcome(response.data?.outcome)
			} catch (e) {
				this.showPublishFailure(e?.response?.data ?? {})
			} finally {
				this.busy = false
			}
		},

		/**
		 * Send the course and its confirmations to the publish endpoint.
		 *
		 * @return {Promise<object>} The axios response.
		 */
		sendPublish() {
			return axios.post(
				generateUrl(coursePackagePublishUrl()),
				this.shareConfirmations(),
			)
		},

		/**
		 * Show what a publish answered.
		 *
		 * @param {string} outcome The server's outcome.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
		 */
		showPublishOutcome(outcome) {
			if (outcome === 'ok') {
				this.done = this.t('learniq', 'Published to the course marketplace.')
			} else {
				this.error = publishText(outcome)
			}
		},

		/**
		 * Show a failed publish: the sharing gate's reasons, else the outcome.
		 *
		 * @param {object} body The error body.
		 * @return {void}
		 */
		showPublishFailure(body) {
			if (!this.takeBlockers(body)) {
				this.error = publishText(body.outcome)
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
		 * @spec openspec/specs/course-management/spec.md#requirement-the-export-page-offers-sharing-with-the-confirmations
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
	},
}
</script>

<style scoped>
.export-request__card {
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
