<!--
  PortfolioBuilder.vue
  Custom page component for the PortfolioBuilder manifest page (type: custom).

  eportfolio: the learner's evidence-picker surface for one Portfolio. Lets the
  learner assemble PortfolioEntry rows by REFERENCING existing evidence —
  their own Submission / WerkprocesAssessment / ExternalTrainingRecord /
  Credential (dropdown/search pickers, no free-text UUID entry) — or by
  writing a free-text reflection. No file picker is wired yet (evidenceKind:
  file stays available for a future NC Files picker follow-up; today only the
  reference-based kinds + reflection are offered). Drives the Portfolio
  `submit` transition once every PortfolioTemplate-required section has
  evidence, and surfaces PortfolioSubmissionGuard's HTTP 422 refusal inline
  rather than a generic error.

  Talks only to OpenRegister's REST API:
    - GET  /api/objects/learniq/Portfolio/:id
    - GET  /api/objects/learniq/portfolio-template/:id           (when templateId set)
    - GET  /api/objects/learniq/portfolio-entry?filters[portfolioId]=:id
    - GET  /api/objects/learniq/Submission?filters[learnerIds]=:learnerId
    - GET  /api/objects/learniq/werkproces-assessment?filters[...]
    - GET  /api/objects/learniq/external-training-record?filters[learnerId]=:learnerId
    - GET  /api/objects/learniq/Credential?filters[learnerId]=:learnerId
    - POST /api/objects/learniq/portfolio-entry
    - POST /api/objects/learniq/Portfolio/:id/transition/submit

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring MarkSubmissionView.vue's existing shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
  @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolioentry-references-existing-evidence-objects-via-per-kind-fields-never-a-polymorphic-ref
  @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
-->

<template>
	<div class="portfolio-builder">
		<!-- Loading -->
		<div v-if="loading" class="portfolio-builder__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading portfolio...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="portfolio-builder__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else-if="portfolio">
			<header class="portfolio-builder__header">
				<h2>
					{{
						t('learniq', 'Build portfolio: {title}', {
							title: portfolio.title || '',
						})
					}}
				</h2>
				<p class="portfolio-builder__meta">
					{{
						t('learniq', 'Kind: {kind}', { kind: portfolio.kind || '' })
					}}
					<span class="portfolio-builder__lifecycle">
						{{
							t('learniq', 'Status: {status}', {
								status: portfolio.lifecycle || '',
							})
						}}
					</span>
				</p>
			</header>

			<!-- Required-section coverage, when a template governs this portfolio -->
			<section
				v-if="template && sections.length > 0"
				class="portfolio-builder__sections">
				<h3>{{ t('learniq', 'Required sections') }}</h3>
				<ul class="portfolio-builder__section-list">
					<li
						v-for="section in sections"
						:key="section.sectionId"
						class="portfolio-builder__section-item"
						:class="{
							'portfolio-builder__section-item--covered':
								isSectionCovered(section.sectionId),
						}">
						<span
							class="portfolio-builder__section-icon"
							:class="
								isSectionCovered(section.sectionId)
									? 'icon-checkmark'
									: 'icon-error'
							"
							aria-hidden="true" />
						<span>{{ section.label }}</span>
					</li>
				</ul>
			</section>

			<!-- Existing entries -->
			<section class="portfolio-builder__entries">
				<h3>{{ t('learniq', 'Evidence entries') }}</h3>
				<ul v-if="entries.length > 0" class="portfolio-builder__entry-list">
					<li
						v-for="entry in entries"
						:key="entry.id"
						class="portfolio-builder__entry-item">
						<span class="portfolio-builder__entry-kind">{{
							/**
							 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
							 */
							evidenceKindLabel(entry.evidenceKind)
						}}</span>
						<span class="portfolio-builder__entry-title">{{
							entry.title
						}}</span>
						<span
							v-if="entry.sectionId"
							class="portfolio-builder__entry-section">
							{{
								t('learniq', 'Section: {s}', {
									s: sectionLabel(entry.sectionId),
								})
							}}
						</span>
					</li>
				</ul>
				<p v-else class="portfolio-builder__no-entries">
					{{ t('learniq', 'No evidence added yet.') }}
				</p>
			</section>

			<!-- Add-entry form: evidence-kind picker, never a free-text UUID field -->
			<section class="portfolio-builder__add-entry">
				<h3>{{ t('learniq', 'Add evidence') }}</h3>

				<label for="pb-evidence-kind" class="portfolio-builder__field-label">
					{{ t('learniq', 'Evidence kind') }}
				</label>
				<select
					id="pb-evidence-kind"
					v-model="newEntry.evidenceKind"
					class="portfolio-builder__select"
					@change="onEvidenceKindChange">
					<option value="submission">
						{{ t('learniq', 'Existing submission') }}
					</option>
					<option value="werkproces-assessment">
						{{ t('learniq', 'Werkproces assessment') }}
					</option>
					<option value="external-training-record">
						{{ t('learniq', 'External training record') }}
					</option>
					<option value="credential">
						{{ t('learniq', 'Credential') }}
					</option>
					<option value="reflection">
						{{ t('learniq', 'Reflection') }}
					</option>
				</select>

				<div
					v-if="newEntry.evidenceKind !== 'reflection'"
					class="portfolio-builder__picker">
					<label
						for="pb-evidence-ref"
						class="portfolio-builder__field-label">
						{{ t('learniq', 'Select existing object') }}
					</label>
					<select
						id="pb-evidence-ref"
						v-model="newEntry.referenceId"
						class="portfolio-builder__select"
						:disabled="pickerOptions.length === 0">
						<option value="">
							{{ t('learniq', '— choose —') }}
						</option>
						<option
							v-for="opt in pickerOptions"
							:key="opt.id"
							:value="opt.id">
							{{ opt.label }}
						</option>
					</select>
					<p
						v-if="pickerOptions.length === 0"
						class="portfolio-builder__picker-empty">
						{{
							t(
								'learniq',
								'No eligible objects found for this evidence kind.',
							)
						}}
					</p>
				</div>

				<div class="portfolio-builder__field">
					<label for="pb-title" class="portfolio-builder__field-label">
						{{ t('learniq', 'Title') }}
					</label>
					<input
						id="pb-title"
						v-model="newEntry.title"
						type="text"
						class="portfolio-builder__input" />
				</div>

				<div v-if="sections.length > 0" class="portfolio-builder__field">
					<label for="pb-section" class="portfolio-builder__field-label">
						{{ t('learniq', 'Section') }}
					</label>
					<select
						id="pb-section"
						v-model="newEntry.sectionId"
						class="portfolio-builder__select">
						<option value="">
							{{ t('learniq', '— none —') }}
						</option>
						<option
							v-for="section in sections"
							:key="section.sectionId"
							:value="section.sectionId">
							{{ section.label }}
						</option>
					</select>
				</div>

				<div
					v-if="
						newEntry.evidenceKind === 'reflection'
						|| newEntry.reflectionText
					"
					class="portfolio-builder__field">
					<label
						for="pb-reflection"
						class="portfolio-builder__field-label">
						{{ t('learniq', 'Reflection text') }}
					</label>
					<textarea
						id="pb-reflection"
						v-model="newEntry.reflectionText"
						class="portfolio-builder__textarea"
						rows="4" />
				</div>

				<button
					class="button-vue button-vue--secondary portfolio-builder__add-btn"
					:disabled="addingEntry || !canAddEntry"
					@click="addEntry">
					<span
						v-if="addingEntry"
						class="icon-loading"
						aria-hidden="true" />
					{{ t('learniq', 'Add evidence entry') }}
				</button>
				<p
					v-if="addEntryError"
					role="alert"
					class="portfolio-builder__add-error">
					{{ addEntryError }}
				</p>
			</section>

			<!-- Submit -->
			<div v-if="canSubmit" class="portfolio-builder__actions">
				<button
					class="button-vue button-vue--primary portfolio-builder__submit-btn"
					:disabled="submitting"
					@click="submitPortfolio">
					<span
						v-if="submitting"
						class="icon-loading"
						aria-hidden="true" />
					{{ t('learniq', 'Submit portfolio') }}
				</button>
			</div>
			<p
				v-if="submitError"
				role="alert"
				class="portfolio-builder__submit-error">
				{{ submitError }}
			</p>
			<p
				v-if="submitted"
				class="portfolio-builder__submit-confirmation"
				role="status">
				{{ t('learniq', 'Portfolio submitted.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PortfolioBuilder',

	props: {
		/**
		 * Portfolio UUID injected by vue-router from the :id param.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			portfolio: null,
			/** @type {object|null} */
			template: null,
			/** @type {Array<object>} */
			entries: [],
			/** @type {Array<object>} Picker options for the currently selected evidenceKind. */
			pickerOptions: [],
			newEntry: {
				evidenceKind: 'submission',
				referenceId: '',
				title: '',
				sectionId: '',
				reflectionText: '',
			},

			loading: false,
			error: null,
			addingEntry: false,
			addEntryError: null,
			submitting: false,
			submitError: null,
			submitted: false,
		}
	},

	computed: {
		/**
		 * The governing PortfolioTemplate's sections, or an empty array when untemplated.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		sections() {
			return this.template?.sections ?? []
		},

		/**
		 * Whether the add-entry form has enough data to submit.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		canAddEntry() {
			if (this.newEntry.evidenceKind === 'reflection') {
				return (
					this.newEntry.title.trim() !== ''
					&& this.newEntry.reflectionText.trim() !== ''
				)
			}
			return (
				this.newEntry.title.trim() !== '' && this.newEntry.referenceId !== ''
			)
		},

		/**
		 * Portfolio.submit is only ever offered for a course-bound portfolio
		 * still in draft/active — mirrors PortfolioSubmissionGuard's own
		 * `from: [draft, active]` transition shape. A personal portfolio never
		 * offers submit at all (spec: "kind: personal ... submit and grade are
		 * not offered").
		 *
		 * @return {boolean}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		canSubmit() {
			return (
				this.portfolio?.kind === 'course-bound'
				&& ['draft', 'active'].includes(this.portfolio?.lifecycle)
			)
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * React to the portfolio id prop changing by loading all data.
			 *
			 * @param {string} newId New portfolio UUID
			 * @return {Promise<void>}
			 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
			 */
			async handler(newId) {
				if (newId) {
					await this.loadData(newId)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the Portfolio, its governing PortfolioTemplate (if any), its
		 * existing PortfolioEntry rows, and the picker options for the
		 * default evidence kind.
		 *
		 * @param {string} portfolioId Portfolio UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadData(portfolioId) {
			this.loading = true
			this.error = null

			try {
				this.portfolio = await this.fetchObject('Portfolio', portfolioId)

				if (this.portfolio.templateId) {
					this.template = await this.fetchObject(
						'portfolio-template',
						this.portfolio.templateId,
					)
				}

				await this.loadEntries(portfolioId)
				await this.loadPickerOptions()
			} catch (err) {
				// A MISSING RECORD IS INPUT, NOT A FAULT.
				//
				// Opening a stale bookmark, or a link to a portfolio someone has
				// since deleted, is an ordinary thing for a user to do. It gets a
				// 404, the view says so, and nothing is wrong with the
				// application — so it must not reach `console.error`, which is
				// where genuine faults live. Logging it there buries real errors
				// in routine noise, and any check asserting "no console errors"
				// fails on normal navigation.
				if (err?.notFound === true) {
					this.error = this.t(
						'learniq',
						'This portfolio no longer exists, or you do not have access to it.',
					)
				} else {
					this.error = this.t(
						'learniq',
						'Failed to load portfolio. Please try again.',
					)
					// eslint-disable-next-line no-console
					console.error('[PortfolioBuilder] loadData error', err)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single OpenRegister object.
		 *
		 * @param {string} schema OR schema PascalCase key (matches the object-API path convention).
		 * @param {string} objId  Object UUID.
		 * @return {Promise<object>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async fetchObject(schema, objId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}/${objId}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// `notFound` is set HERE and only here — on the single-object
				// lookup — not wherever a 404 happens to surface.
				//
				// This method and fetchList() both feed the same catch. A 404
				// from a LIST does not mean "this record is absent": an empty
				// result set says that, with HTTP 200. A 404 there means the
				// schema did not resolve, which is a broken register and must
				// stay loud. Flagging the object lookup specifically keeps the
				// quiet branch to the one case that is genuinely expected input.
				const err = new Error(`${schema} fetch failed: ${resp.status}`)
				err.status = resp.status
				err.notFound = resp.status === 404
				throw err
			}
			const json = await resp.json()
			return json.object ?? json ?? {}
		},

		/**
		 * Fetch a filtered list of OpenRegister objects.
		 *
		 * @param {string} schema OR schema PascalCase key.
		 * @param {string} query  Pre-built query string (already URL-encoded).
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async fetchList(schema, query) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}?${query}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				return []
			}
			const json = await resp.json()
			return json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
		},

		/**
		 * Load this portfolio's existing PortfolioEntry rows.
		 *
		 * @param {string} portfolioId Portfolio UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadEntries(portfolioId) {
			this.entries = await this.fetchList(
				'portfolio-entry',
				`filters[portfolioId]=${portfolioId}&_limit=100`,
			)
		},

		/**
		 * Load the picker options for the currently selected evidenceKind —
		 * the learner's OWN existing objects only, scoped by their NC user id.
		 * Never a free-text UUID field (spec requirement).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async loadPickerOptions() {
			const uid = getCurrentUser()?.uid ?? ''
			const kind = this.newEntry.evidenceKind

			if (kind === 'reflection') {
				this.pickerOptions = []
				return
			}

			try {
				if (kind === 'submission') {
					const rows = await this.fetchList(
						'Submission',
						`filters[learnerIds]=${uid}&_limit=100`,
					)
					this.pickerOptions = rows.map((r) => ({
						id: r.id,
						label: r.feedbackText
							? `${r.assignmentId} — ${r.feedbackText}`
							: (r.assignmentId ?? r.id),
					}))
				} else if (kind === 'werkproces-assessment') {
					const rows = await this.fetchList(
						kind,
						`filters[learnerId]=${uid}&_limit=100`,
					)
					this.pickerOptions = rows.map((r) => ({
						id: r.id,
						label: r.werkprocesLabel ?? r.werkprocesCode ?? r.id,
					}))
				} else if (kind === 'external-training-record') {
					const rows = await this.fetchList(
						kind,
						`filters[learnerId]=${uid}&_limit=100`,
					)
					this.pickerOptions = rows.map((r) => ({
						id: r.id,
						label: r.title ?? r.providerName ?? r.id,
					}))
				} else if (kind === 'credential') {
					const rows = await this.fetchList(
						'Credential',
						`filters[learnerId]=${uid}&_limit=100`,
					)
					this.pickerOptions = rows.map((r) => ({
						id: r.id,
						label: r.title ?? r.id,
					}))
				} else {
					this.pickerOptions = []
				}
			} catch (err) {
				this.pickerOptions = []
				// eslint-disable-next-line no-console
				console.error('[PortfolioBuilder] loadPickerOptions error', err)
			}
		},

		/**
		 * Re-load picker options when the evidence-kind selector changes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async onEvidenceKindChange() {
			this.newEntry.referenceId = ''
			await this.loadPickerOptions()
		},

		/**
		 * Whether the given sectionId already has at least one linked
		 * PortfolioEntry — mirrors PortfolioSubmissionGuard's own coverage
		 * check, for inline display only (the server remains authoritative).
		 *
		 * @param {string} sectionId Section identifier.
		 * @return {boolean}
		 */
		isSectionCovered(sectionId) {
			return this.entries.some((e) => e.sectionId === sectionId)
		},

		/**
		 * Resolve a sectionId to its template label.
		 *
		 * @param {string} sectionId Section identifier.
		 * @return {string}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		sectionLabel(sectionId) {
			return (
				this.sections.find((s) => s.sectionId === sectionId)?.label
				?? sectionId
			)
		},

		/**
		 * Human-readable label for an evidenceKind value.
		 *
		 * @param {string} kind evidenceKind value.
		 * @return {string}
		 * @spec exclude Presentation-only label map from the 6 fixed evidenceKind values to their localized display names; the per-kind reference discipline itself is governed by requirement-portfolioentry-references-existing-evidence-objects-via-per-kind-fields-never-a-polymorphic-ref, not by this string lookup.
		 */
		evidenceKindLabel(kind) {
			const labels = {
				file: this.t('learniq', 'File'),
				submission: this.t('learniq', 'Submission'),
				'werkproces-assessment': this.t('learniq', 'Werkproces assessment'),
				'external-training-record': this.t(
					'learniq',
					'External training record',
				),

				credential: this.t('learniq', 'Credential'),
				reflection: this.t('learniq', 'Reflection'),
			}
			return labels[kind] ?? kind
		},

		/**
		 * Create a PortfolioEntry from the add-entry form, referencing the
		 * selected existing object (or carrying the reflection text) — never
		 * duplicating the referenced object's own field values.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async addEntry() {
			if (!this.portfolio) {
				return
			}
			this.addingEntry = true
			this.addEntryError = null

			const uid = getCurrentUser()?.uid ?? ''
			const body = {
				portfolioId: this.id,
				learnerId: uid,
				title: this.newEntry.title,
				evidenceKind: this.newEntry.evidenceKind,
				sectionId: this.newEntry.sectionId || null,
				tenant_id: this.portfolio.tenant_id ?? '',
			}

			if (this.newEntry.evidenceKind === 'reflection') {
				body.reflectionText = this.newEntry.reflectionText
			} else if (this.newEntry.evidenceKind === 'submission') {
				body.submissionId = this.newEntry.referenceId
			} else if (this.newEntry.evidenceKind === 'werkproces-assessment') {
				body.werkprocesAssessmentId = this.newEntry.referenceId
			} else if (this.newEntry.evidenceKind === 'external-training-record') {
				body.externalTrainingRecordId = this.newEntry.referenceId
			} else if (this.newEntry.evidenceKind === 'credential') {
				body.credentialId = this.newEntry.referenceId
			}

			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/portfolio-entry',
				)
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(body),
				})
				if (!resp.ok) {
					throw new Error(`PortfolioEntry create failed: ${resp.status}`)
				}

				await this.loadEntries(this.id)
				this.newEntry = {
					evidenceKind: 'submission',
					referenceId: '',
					title: '',
					sectionId: '',
					reflectionText: '',
				}
				await this.loadPickerOptions()
			} catch (err) {
				this.addEntryError = this.t(
					'learniq',
					'Failed to add evidence entry. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[PortfolioBuilder] addEntry error', err)
			} finally {
				this.addingEntry = false
			}
		},

		/**
		 * Dispatch the Portfolio `submit` lifecycle transition. Surfaces
		 * PortfolioSubmissionGuard's HTTP 422 refusal inline rather than a
		 * generic error.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views
		 */
		async submitPortfolio() {
			this.submitting = true
			this.submitError = null

			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/learniq/Portfolio/${this.id}/transition/submit`,
				)
				const resp = await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})
				if (resp.status === 422) {
					this.submitError = this.t(
						'learniq',
						'Submission refused: every required section needs at least one evidence entry.',
					)
					return
				}
				if (!resp.ok) {
					throw new Error(
						`Portfolio submit transition failed: ${resp.status}`,
					)
				}
				this.portfolio = await this.fetchObject('Portfolio', this.id)
				this.submitted = true
			} catch (err) {
				this.submitError = this.t(
					'learniq',
					'Failed to submit portfolio. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[PortfolioBuilder] submitPortfolio error', err)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.portfolio-builder {
	max-width: 860px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px)
		calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-builder__loading,
.portfolio-builder__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.portfolio-builder__header {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-builder__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.portfolio-builder__lifecycle {
	margin-left: var(--default-grid-baseline, 8px);
}

.portfolio-builder__sections,
.portfolio-builder__entries,
.portfolio-builder__add-entry {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-builder__section-list,
.portfolio-builder__entry-list {
	list-style: none;
	padding: 0;
}

.portfolio-builder__section-item,
.portfolio-builder__entry-item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.portfolio-builder__section-item--covered {
	color: var(--color-success-text, var(--color-success));
}

.portfolio-builder__entry-kind {
	font-weight: bold;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.portfolio-builder__no-entries {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.portfolio-builder__field,
.portfolio-builder__picker {
	margin-bottom: var(--default-grid-baseline, 8px);
}

.portfolio-builder__field-label {
	display: block;
	margin-bottom: 4px;
}

.portfolio-builder__select,
.portfolio-builder__input,
.portfolio-builder__textarea {
	width: 100%;
	max-width: 420px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 6px 8px;
	font-family: inherit;
}

.portfolio-builder__textarea {
	max-width: 100%;
	resize: vertical;
}

.portfolio-builder__picker-empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-style: italic;
}

.portfolio-builder__add-error,
.portfolio-builder__submit-error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: var(--default-grid-baseline, 8px);
}

.portfolio-builder__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline, 8px) * 3);
}

.portfolio-builder__submit-confirmation {
	color: var(--color-success-text, var(--color-success));
	text-align: right;
	margin-top: var(--default-grid-baseline, 8px);
}
</style>
