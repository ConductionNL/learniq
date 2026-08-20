<!--
  MyLearningRecordView.vue
  Custom page component for the MyLearningRecordView manifest page (type: custom).

  The learner's aggregate portable-learning-record dashboard: composes
  LearningRecordAggregationService's live, read-only view (via the bespoke
  GET /api/learning-records/me endpoint — the RBAC-gap read no declarative
  schema can serve), an export action (generate + coverage report), and a
  share panel (create with a mandatory expiry, list, revoke).

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  same pattern as CoursePackageImportView.vue/PortfolioBuilder.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
  @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
  @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-learner-can-grant-a-time-boxed-revocable-share-of-one-export-to-a-named-external-recipient
-->

<template>
	<div class="my-learning-record">
		<header class="my-learning-record__header">
			<h2 class="my-learning-record__heading">
				{{ t('learniq', 'My learning record') }}
			</h2>
			<p class="my-learning-record__intro">
				{{
					t(
						'learniq',
						'Everything you have earned, everywhere in Learniq — composed live, read-only. Nothing here can be edited or deleted from this page.',
					)
				}}
			</p>
		</header>

		<p v-if="loading" class="my-learning-record__loading">
			{{ t('learniq', 'Loading your record…') }}
		</p>
		<p v-else-if="loadError" role="alert" class="my-learning-record__error">
			{{ loadError }}
		</p>

		<template v-else-if="record">
			<!-- Composed, read-only sections -->
			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Credentials') }} ({{
						record.credentials.length
					}})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="c in record.credentials" :key="c.id">
						{{ c.kind }} — {{ c.issuedAt }}
					</li>
					<li
						v-if="record.credentials.length === 0"
						class="my-learning-record__empty">
						{{ t('learniq', 'None yet.') }}
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Final grades') }} ({{
						record.finalGrades.length
					}})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="g in record.finalGrades" :key="g.id">
						{{ g.value ?? '—' }} —
						{{
							g.passed === true
								? t('learniq', 'passed')
								: t('learniq', 'not yet passed')
						}}
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Competency attainments') }} ({{
						record.competencyAttainments.length
					}})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="ca in record.competencyAttainments" :key="ca.id">
						{{ ca.proficiencyLevelId ?? t('learniq', 'in progress') }}
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Portfolios') }} ({{ record.portfolios.length }})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="p in record.portfolios" :key="p.id">
						{{ p.title }} ({{ p.lifecycle }})
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Verified external training') }} ({{
						record.externalTrainingRecords.length
					}})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="e in record.externalTrainingRecords" :key="e.id">
						{{ e.title }} — {{ e.provider }}
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Work-placement (BPV)') }} ({{
						record.bpvPlacements.length
					}})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="b in record.bpvPlacements" :key="b.id">
						{{ b.trainingCompanyName }} — {{ b.lifecycle }}
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Lesson completion') }} ({{
						record.lessonCompletions.length
					}}
					{{ t('learniq', 'courses') }})
				</h3>
				<ul class="my-learning-record__list">
					<li v-for="(l, idx) in record.lessonCompletions" :key="idx">
						{{ l.courseId }} — {{ l.completedCount }}
						{{ t('learniq', 'lessons') }}
						<span v-if="l.percentage !== null">
							({{ l.percentage }}%)</span
						>
					</li>
				</ul>
			</section>

			<section class="my-learning-record__section">
				<h3>
					{{ t('learniq', 'Report cards') }} ({{
						record.reportCards.length
					}})
				</h3>
			</section>

			<!-- Export -->
			<section class="my-learning-record__section my-learning-record__export">
				<h3>{{ t('learniq', 'Export my record') }}</h3>
				<p class="my-learning-record__hint">
					{{
						t(
							'learniq',
							'Generates a signed, self-contained bundle you can carry to another school or an employer, with an honest report of exactly what is included.',
						)
					}}
				</p>

				<div class="my-learning-record__period">
					<label for="period-from">{{
						t('learniq', 'From (optional)')
					}}</label>
					<input id="period-from" v-model="periodFrom" type="date" />
					<label for="period-to">{{
						t('learniq', 'To (optional)')
					}}</label>
					<input id="period-to" v-model="periodTo" type="date" />
				</div>

				<button
					class="button-vue button-vue--primary"
					:disabled="generating"
					@click="generateExport">
					<span
						v-if="generating"
						class="icon-loading"
						aria-hidden="true" />
					{{
						generating
							? t('learniq', 'Generating…')
							: t('learniq', 'Generate export')
					}}
				</button>

				<p v-if="exportError" role="alert" class="my-learning-record__error">
					{{ exportError }}
				</p>

				<div v-if="latestExport" class="my-learning-record__export-result">
					<p
						v-if="latestExport.lifecycle === 'generated'"
						class="my-learning-record__success">
						{{
							t(
								'learniq',
								'Export generated. The signed bundle is stored at {ref} in your Files.',
								{ ref: latestExport.bundleRef },
							)
						}}
					</p>
					<p
						v-else-if="latestExport.errorMessage"
						role="alert"
						class="my-learning-record__error">
						{{ latestExport.errorMessage }}
					</p>

					<table
						v-if="
							latestExport.coverageReport
							&& latestExport.coverageReport.length
						"
						class="my-learning-record__table">
						<thead>
							<tr>
								<th scope="col">{{ t('learniq', 'Source') }}</th>
								<th scope="col">{{ t('learniq', 'Outcome') }}</th>
								<th scope="col">{{ t('learniq', 'Reason') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="(entry, idx) in latestExport.coverageReport"
								:key="idx">
								<td>{{ entry.sourceTitle }}</td>
								<td>
									<span
										:class="`my-learning-record__badge my-learning-record__badge--${entry.outcome}`">
										{{ entry.outcome }}
									</span>
								</td>
								<td>{{ entry.reason || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- Share -->
			<section
				v-if="latestExport && latestExport.lifecycle === 'generated'"
				class="my-learning-record__section my-learning-record__share">
				<h3>{{ t('learniq', 'Share my export') }}</h3>
				<p class="my-learning-record__hint">
					{{
						t(
							'learniq',
							'Grant a named recipient — an employer, a receiving school — time-boxed access to the most recently generated export.',
						)
					}}
				</p>

				<div class="my-learning-record__share-form">
					<label for="recipient-name">{{
						t('learniq', 'Recipient name')
					}}</label>
					<input
						id="recipient-name"
						v-model="newShare.recipientName"
						type="text" />

					<label for="recipient-email">{{
						t('learniq', 'Recipient email (optional)')
					}}</label>
					<!-- autocomplete="off", not "email": this collects a THIRD PARTY's address
					     (an employer, a receiving school), never the signed-in user's own, so
					     offering the user's own email here would be the wrong suggestion. -->
					<input
						id="recipient-email"
						v-model="newShare.recipientEmail"
						type="email"
						autocomplete="off" />

					<label for="expires-at">{{ t('learniq', 'Expires on') }}</label>
					<input
						id="expires-at"
						v-model="newShare.expiresAt"
						type="date" />

					<button
						class="button-vue button-vue--primary"
						:disabled="
							creatingShare
							|| !newShare.recipientName
							|| !newShare.expiresAt
						"
						@click="createShare">
						{{ t('learniq', 'Create share') }}
					</button>

					<p
						v-if="!newShare.expiresAt && shareTouched"
						role="alert"
						class="my-learning-record__error">
						{{
							t(
								'learniq',
								'An expiry date is required before a share can be created.',
							)
						}}
					</p>
					<p
						v-if="shareError"
						role="alert"
						class="my-learning-record__error">
						{{ shareError }}
					</p>
				</div>

				<table v-if="shares.length" class="my-learning-record__table">
					<thead>
						<tr>
							<th scope="col">{{ t('learniq', 'Recipient') }}</th>
							<th scope="col">{{ t('learniq', 'Expires') }}</th>
							<th scope="col">{{ t('learniq', 'Status') }}</th>
							<th scope="col">
								{{ t('learniq', 'Verification link') }}
							</th>
							<th scope="col">{{ t('learniq', 'Action') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="share in shares" :key="share.id">
							<td>{{ share.recipientName }}</td>
							<td>{{ share.expiresAt }}</td>
							<td>{{ share.lifecycle }}</td>
							<td>
								<a
									:href="verifyUrl(share.id)"
									target="_blank"
									rel="noopener"
									>{{ t('learniq', 'Open') }}</a
								>
							</td>
							<td>
								<button
									v-if="share.lifecycle === 'active'"
									class="button-vue"
									@click="revokeShare(share.id)">
									{{ t('learniq', 'Revoke') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'MyLearningRecordView',

	data() {
		return {
			loading: true,
			loadError: null,
			record: null,
			periodFrom: '',
			periodTo: '',
			generating: false,
			exportError: null,
			latestExport: null,
			shares: [],
			creatingShare: false,
			shareError: null,
			shareTouched: false,
			newShare: {
				recipientName: '',
				recipientEmail: '',
				expiresAt: '',
			},
		}
	},

	async mounted() {
		await this.loadRecord()
	},

	methods: {
		/**
		 * Load the calling user's own composed learning-record trajectory.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-learner-opens-their-aggregate-record-and-sees-composed-read-only-data
		 */
		async loadRecord() {
			this.loading = true
			this.loadError = null

			try {
				const url = generateUrl('/apps/learniq/api/learning-records/me')
				const resp = await fetch(url, {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})

				// A 404 here is an EXPECTED state, not a failure: it means this
				// Nextcloud user has no bound LearnerProfile, so there is no record
				// to show yet. Treating it as an exception made the component log
				// `console.error('[MyLearningRecordView] loadRecord error', ...)`
				// on a perfectly normal account — noise that is indistinguishable
				// from a real outage in the console, and which the e2e suite
				// correctly flags as fatal. Render the declared error branch with an
				// accurate message instead of throwing.
				if (resp.status === 404) {
					this.loadError = this.t(
						'learniq',
						'You do not have a learning record yet. A learning record is created once you are enrolled as a learner.',
					)
					return
				}

				if (!resp.ok) {
					throw new Error(`Failed to load learning record: ${resp.status}`)
				}

				this.record = await resp.json()
			} catch (err) {
				this.loadError = this.t(
					'learniq',
					'Could not load your learning record. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[MyLearningRecordView] loadRecord error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a LearningRecordExport in `requested`, then fire its `generate`
		 * transition, then re-fetch the resulting object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
		 */
		async generateExport() {
			if (!this.record) return

			this.generating = true
			this.exportError = null

			const uid = getCurrentUser()?.uid ?? ''
			const nowIso = new Date().toISOString()

			try {
				const createUrl = generateUrl(
					'/apps/openregister/api/objects/learniq/learning-record-export',
				)
				const createResp = await fetch(createUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						learnerId: uid,
						learnerRef: this.record.learnerRef,
						requestedBy: uid,
						requestedAt: nowIso,
						periodFrom: this.periodFrom || null,
						periodTo: this.periodTo || null,
						tenant_id: this.record.tenant_id ?? '',
					}),
				})

				if (!createResp.ok) {
					throw new Error(
						`LearningRecordExport create failed: ${createResp.status}`,
					)
				}

				const created = await createResp.json()
				const exportId = created.id ?? created.uuid

				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/learniq/learning-record-export/${exportId}/transition/generate`,
				)
				await fetch(transitionUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})

				const finalUrl = generateUrl(
					`/apps/openregister/api/objects/learniq/learning-record-export/${exportId}`,
				)
				const finalResp = await fetch(finalUrl, {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})
				const finalJson = await finalResp.json()
				this.latestExport = finalJson.object ?? finalJson

				this.shares = []
			} catch (err) {
				this.exportError = this.t(
					'learniq',
					'Failed to generate the export. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[MyLearningRecordView] generateExport error', err)
			} finally {
				this.generating = false
			}
		},

		/**
		 * Create a LearningRecordShare for the latest generated export, then
		 * fire its `grant` transition. Blocked until an expiry date is set.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-learner-creates-a-share-with-a-mandatory-expiry
		 */
		async createShare() {
			this.shareTouched = true
			if (
				!this.newShare.recipientName
				|| !this.newShare.expiresAt
				|| !this.latestExport
			) {
				return
			}

			this.creatingShare = true
			this.shareError = null

			const uid = getCurrentUser()?.uid ?? ''

			try {
				const createUrl = generateUrl(
					'/apps/openregister/api/objects/learniq/learning-record-share',
				)
				const createResp = await fetch(createUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						learningRecordExportId:
							this.latestExport.id ?? this.latestExport.uuid,
						learnerId: uid,
						learnerRef: this.record.learnerRef,
						recipientName: this.newShare.recipientName,
						recipientEmail: this.newShare.recipientEmail || null,
						sharedBy: uid,
						expiresAt: new Date(this.newShare.expiresAt).toISOString(),
						tenant_id: this.record.tenant_id ?? '',
					}),
				})

				if (!createResp.ok) {
					throw new Error(
						`LearningRecordShare create failed: ${createResp.status}`,
					)
				}

				const created = await createResp.json()
				const shareId = created.id ?? created.uuid

				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/learniq/learning-record-share/${shareId}/transition/grant`,
				)
				await fetch(transitionUrl, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})

				await this.loadShares()
				this.newShare = {
					recipientName: '',
					recipientEmail: '',
					expiresAt: '',
				}
				this.shareTouched = false
			} catch (err) {
				this.shareError = this.t(
					'learniq',
					'Failed to create the share. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[MyLearningRecordView] createShare error', err)
			} finally {
				this.creatingShare = false
			}
		},

		/**
		 * Fire the `revoke` transition on a share, then reload the share list.
		 *
		 * @param {string} shareId LearningRecordShare UUID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-revoking-a-share-immediately-invalidates-its-verification-link
		 */
		async revokeShare(shareId) {
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/learniq/learning-record-share/${shareId}/transition/revoke`,
				)
				await fetch(url, {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({}),
				})
				await this.loadShares()
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[MyLearningRecordView] revokeShare error', err)
			}
		},

		/**
		 * Load every LearningRecordShare for the latest generated export.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
		 */
		async loadShares() {
			if (!this.latestExport) return

			const exportId = this.latestExport.id ?? this.latestExport.uuid
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/learning-record-share?filters[learningRecordExportId]=${exportId}&limit=100`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				this.shares = []
				return
			}
			const json = await resp.json()
			this.shares =
				json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
		},

		/**
		 * Build the absolute public verification URL for a share.
		 *
		 * @param {string} shareId LearningRecordShare UUID.
		 * @return {string}
		 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
		 */
		verifyUrl(shareId) {
			return generateUrl(
				`/apps/learniq/learning-record-shares/${shareId}/verify`,
			)
		},
	},
}
</script>

<style scoped>
.my-learning-record {
	max-width: 1080px;
	margin: 0 auto;
	padding: var(--default-grid-baseline, 8px)
		calc(var(--default-grid-baseline, 8px) * 2);
}

.my-learning-record__heading {
	font-size: var(--default-font-size, 15px);
	font-weight: bold;
	margin-bottom: calc(var(--default-grid-baseline, 8px));
}

.my-learning-record__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.my-learning-record__section {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 3);
}

.my-learning-record__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.my-learning-record__empty {
	color: var(--color-text-maxcontrast);
}

.my-learning-record__error {
	color: var(--color-error);
	margin-top: var(--default-grid-baseline, 8px);
}

.my-learning-record__success {
	color: var(--color-success);
}

.my-learning-record__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.my-learning-record__period,
.my-learning-record__share-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.my-learning-record__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: calc(var(--default-grid-baseline, 8px) * 2);
}

.my-learning-record__table th,
.my-learning-record__table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.my-learning-record__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.85em;
	font-weight: 600;
}

.my-learning-record__badge--included,
.my-learning-record__badge--recognized {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.my-learning-record__badge--summarized {
	background: var(--color-warning);
	color: var(--color-primary-element-text, #fff);
}

.my-learning-record__badge--omitted,
.my-learning-record__badge--unrecognized {
	background: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}
</style>
