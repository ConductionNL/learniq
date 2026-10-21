<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ExamCaseDossierView: the shared detail page of the ExemptionCase and
 FraudCase manifest pages, selected by `config.schema` ("exemption-case" |
 "fraud-case").

 The server withholds a fraud case's hearing records, decision rationale and
 sanction from everyone outside the exam board (property authorization in
 the register), so the accused and the reporter never receive them. The
 decision forms render for exam board members only (`exam-board` in the
 `dashboardRoles` initial state).

 @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
 @spec openspec/specs/exam-board/spec.md#requirement-frontend-is-declarative-with-one-shared-custom-detail-view
-->

<template>
	<div class="exam-case-dossier">
		<div v-if="loading" class="exam-case-dossier__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading case...') }}</span>
		</div>

		<div v-else-if="error" class="exam-case-dossier__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<div v-else-if="!caseObject" class="exam-case-dossier__empty" role="status">
			<p>{{ t('learniq', 'Case not found.') }}</p>
		</div>

		<template v-else>
			<header class="exam-case-dossier__header">
				<h2>
					{{
						isExemption
							? t('learniq', 'Exemption request')
							: t('learniq', 'Fraud case')
					}}
				</h2>
				<span
					class="exam-case-dossier__status"
					:class="'exam-case-dossier__status--' + caseObject.lifecycle">
					{{ caseObject.lifecycle }}
				</span>
			</header>

			<!-- ExemptionCase data -->
			<section v-if="isExemption" class="exam-case-dossier__section">
				<dl class="exam-case-dossier__fields">
					<dt>{{ t('learniq', 'Learner') }}</dt>
					<dd>{{ caseObject.learnerId }}</dd>
					<dt>{{ t('learniq', 'Component') }}</dt>
					<dd>{{ caseObject.componentId }}</dd>
					<dt>{{ t('learniq', 'Grounds') }}</dt>
					<dd>{{ caseObject.groundsKind }}</dd>
					<dt>{{ t('learniq', 'Description') }}</dt>
					<dd>{{ caseObject.groundsDescription }}</dd>
					<template v-if="caseObject.decisionRationale">
						<dt>{{ t('learniq', 'Decision rationale') }}</dt>
						<dd>{{ caseObject.decisionRationale }}</dd>
						<dt>{{ t('learniq', 'Policy reference') }}</dt>
						<dd>{{ caseObject.policyReference }}</dd>
					</template>
				</dl>

				<div
					v-if="canDecide && caseObject.lifecycle === 'submitted'"
					class="exam-case-dossier__actions">
					<button
						class="button-vue"
						:disabled="saving"
						@click="startAssessment">
						{{ t('learniq', 'Start assessment') }}
					</button>
				</div>

				<div
					v-if="canDecide && caseObject.lifecycle === 'in-assessment'"
					class="exam-case-dossier__decision-form">
					<label for="exemption-rationale">{{
						t('learniq', 'Decision rationale')
					}}</label>
					<textarea id="exemption-rationale" v-model="decisionRationale" />
					<label for="exemption-policy">{{
						t('learniq', 'Policy reference')
					}}</label>
					<input
						id="exemption-policy"
						v-model="policyReference"
						type="text" />
					<div class="exam-case-dossier__actions">
						<button
							class="button-vue"
							:disabled="saving"
							@click="grantExemption">
							{{ t('learniq', 'Grant') }}
						</button>
						<button
							class="button-vue button-vue--error"
							:disabled="saving"
							@click="rejectExemption">
							{{ t('learniq', 'Reject') }}
						</button>
					</div>
				</div>

				<div
					v-if="
						canWithdraw
							&& ['submitted', 'in-assessment'].includes(caseObject.lifecycle)
					"
					class="exam-case-dossier__actions">
					<button
						class="button-vue"
						:disabled="saving"
						@click="withdrawExemption">
						{{ t('learniq', 'Withdraw') }}
					</button>
				</div>
			</section>

			<!-- FraudCase data -->
			<section v-else class="exam-case-dossier__section">
				<dl class="exam-case-dossier__fields">
					<dt>{{ t('learniq', 'Accused learner') }}</dt>
					<dd>{{ caseObject.accusedLearnerId }}</dd>
					<dt>{{ t('learniq', 'Reported by') }}</dt>
					<dd>{{ caseObject.reporterId }}</dd>
					<dt>{{ t('learniq', 'Allegation') }}</dt>
					<dd>{{ caseObject.allegation }}</dd>
					<template v-if="caseObject.verdict">
						<dt>{{ t('learniq', 'Verdict') }}</dt>
						<dd>{{ caseObject.verdict }}</dd>
						<dt>{{ t('learniq', 'Appeal deadline') }}</dt>
						<dd>{{ caseObject.appealDeadline }}</dd>
					</template>
				</dl>

				<div v-if="canDecide" class="exam-case-dossier__internal">
					<h3>{{ t('learniq', 'Hearing records') }}</h3>
					<ul v-if="(caseObject.hearingRecords || []).length > 0">
						<li
							v-for="(record, idx) in caseObject.hearingRecords"
							:key="idx">
							{{ record.heldAt }} — {{ record.notes }}
						</li>
					</ul>
					<p v-else>
						{{ t('learniq', 'No hearing records yet.') }}
					</p>

					<template v-if="caseObject.decisionRationale">
						<h3>{{ t('learniq', 'Decision rationale') }}</h3>
						<p>{{ caseObject.decisionRationale }}</p>
					</template>
					<template v-if="caseObject.sanctionType">
						<h3>{{ t('learniq', 'Sanction') }}</h3>
						<p>
							{{ caseObject.sanctionType }} —
							{{ caseObject.sanctionDurationMonths }}
							{{ t('learniq', 'month(s)') }} —
							{{ caseObject.sanctionScope }}
						</p>
					</template>
				</div>
				<p v-else class="exam-case-dossier__redacted-notice">
					{{
						t(
							'learniq',
							'Hearing details and decision internals are only visible to the exam board.',
						)
					}}
				</p>

				<div
					v-if="canDecide && caseObject.lifecycle === 'reported'"
					class="exam-case-dossier__decision-form">
					<label for="hearing-date">{{
						t('learniq', 'Hearing date')
					}}</label>
					<input
						id="hearing-date"
						v-model="hearingDate"
						type="datetime-local" />
					<div class="exam-case-dossier__actions">
						<button
							class="button-vue"
							:disabled="saving"
							@click="scheduleHearing">
							{{ t('learniq', 'Schedule hearing') }}
						</button>
					</div>
				</div>

				<div
					v-if="canDecide && caseObject.lifecycle === 'hearing-scheduled'"
					class="exam-case-dossier__actions">
					<button
						class="button-vue"
						:disabled="saving"
						@click="holdHearing">
						{{ t('learniq', 'Record hearing held') }}
					</button>
				</div>

				<div
					v-if="canDecide && caseObject.lifecycle === 'heard'"
					class="exam-case-dossier__decision-form">
					<label for="fraud-verdict">{{ t('learniq', 'Verdict') }}</label>
					<select id="fraud-verdict" v-model="verdict">
						<option value="" disabled>
							{{ t('learniq', 'Select a verdict') }}
						</option>
						<option value="fraud-proven">
							{{ t('learniq', 'Fraud proven') }}
						</option>
						<option value="unfounded">
							{{ t('learniq', 'Unfounded') }}
						</option>
					</select>
					<label for="fraud-rationale">{{
						t('learniq', 'Decision rationale')
					}}</label>
					<textarea id="fraud-rationale" v-model="decisionRationale" />

					<template v-if="verdict === 'fraud-proven'">
						<label for="sanction-type">{{
							t('learniq', 'Sanction type')
						}}</label>
						<select id="sanction-type" v-model="sanctionType">
							<option value="" disabled>
								{{ t('learniq', 'Select a sanction') }}
							</option>
							<option value="warning">
								{{ t('learniq', 'Warning') }}
							</option>
							<option value="grade-annulment">
								{{ t('learniq', 'Grade annulment') }}
							</option>
							<option value="resubmission-required">
								{{ t('learniq', 'Resubmission required') }}
							</option>
							<option value="suspension">
								{{ t('learniq', 'Suspension') }}
							</option>
							<option value="exclusion">
								{{ t('learniq', 'Exclusion') }}
							</option>
						</select>
						<label for="sanction-duration">{{
							t('learniq', 'Sanction duration (months, max 12)')
						}}</label>
						<input
							id="sanction-duration"
							v-model.number="sanctionDurationMonths"
							type="number"
							min="1"
							max="12" />
						<label for="sanction-scope">{{
							t('learniq', 'Sanction scope')
						}}</label>
						<select id="sanction-scope" v-model="sanctionScope">
							<option value="" disabled>
								{{ t('learniq', 'Select a scope') }}
							</option>
							<option value="single-assessment">
								{{ t('learniq', 'Single assessment') }}
							</option>
							<option value="course">
								{{ t('learniq', 'Course') }}
							</option>
							<option value="programme">
								{{ t('learniq', 'Programme') }}
							</option>
						</select>
					</template>

					<div class="exam-case-dossier__actions">
						<button
							class="button-vue"
							:disabled="saving"
							@click="decideFraudCase">
							{{ t('learniq', 'Decide') }}
						</button>
					</div>
				</div>

				<div
					v-if="
						canDecide
							&& ['reported', 'hearing-scheduled'].includes(
								caseObject.lifecycle,
							)
					"
					class="exam-case-dossier__actions">
					<button
						class="button-vue button-vue--error"
						:disabled="saving"
						@click="dismissFraudCase">
						{{ t('learniq', 'Dismiss') }}
					</button>
				</div>
			</section>

			<p v-if="saveError" role="alert" class="exam-case-dossier__error-inline">
				{{ saveError }}
			</p>
		</template>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { transitionUrl as objectTransitionUrl } from '../utils/customPages.js'
import { canWithdrawExemption, isExamBoard } from '../utils/examBoard.js'

export default {
	name: 'ExamCaseDossierView',

	props: {
		/** OpenRegister object UUID, injected by vue-router from the `:id` route param. */
		id: {
			type: String,
			required: true,
		},

		/** `config.register` forwarded by CnPageRenderer. */
		register: {
			type: String,
			default: 'learniq',
		},

		/** `config.schema` forwarded by CnPageRenderer — "exemption-case" | "fraud-case". */
		schema: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			caseObject: null,
			loading: false,
			saving: false,
			error: null,
			saveError: null,
			decisionRationale: '',
			policyReference: '',
			hearingDate: '',
			verdict: '',
			sanctionType: '',
			sanctionDurationMonths: null,
			sanctionScope: '',
			dashboardRoles: loadState('learniq', 'dashboardRoles', []),
		}
	},

	computed: {
		isExemption() {
			return this.schema === 'exemption-case'
		},

		/**
		 * Whether the signed-in user is on the exam board, who alone gets the
		 * decision forms and the case internals.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		canDecide() {
			return isExamBoard(this.dashboardRoles)
		},

		/**
		 * Whether the signed-in user may withdraw an exemption request.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		canWithdraw() {
			return canWithdrawExemption(this.dashboardRoles)
		},
	},

	watch: {
		id: {
			immediate: true,
			handler() {
				this.loadCase()
			},
		},
	},

	methods: {
		/**
		 * The OpenRegister object endpoint for this case.
		 *
		 * ⚠️ ADDRESS THE SCHEMA BY ITS SLUG, NEVER BY ITS TITLE.
		 *
		 * This used to translate `config.schema` through a
		 * `{'exemption-case': 'ExemptionCase', 'fraud-case': 'FraudCase'}` map
		 * before building the url. OpenRegister resolves a schema identifier by
		 * SLUGIFYING it and matching the slug, so `ExemptionCase` becomes
		 * `exemptioncase`, which matches nothing. Measured against a live
		 * instance holding one real ExemptionCase row:
		 *
		 *   .../objects/learniq/ExemptionCase/<uuid>   -> 404
		 *   .../objects/learniq/exemptioncase/<uuid>   -> 404
		 *   .../objects/learniq/Exemption-Case/<uuid>  -> 200
		 *   .../objects/learniq/exemption-case/<uuid>  -> 200
		 *
		 * So BOTH dossier routes (`/exam-board/exemptions/:id` and
		 * `/exam-board/fraud-cases/:id`) could never load a case: the view
		 * rendered its "Case not found." branch for every real id. A
		 * single-word title hides this — `Item` slugifies to `item` and happens
		 * to resolve — which is why the same pattern looks fine elsewhere.
		 *
		 * `config.schema` in the manifest is already the slug, so pass it
		 * through untouched.
		 *
		 * @param {string} suffix Optional path suffix appended to the object url.
		 * @return {string} The generated OpenRegister object url.
		 * @spec openspec/specs/exam-board/spec.md#requirement-frontend-is-declarative-with-one-shared-custom-detail-view
		 */
		objectUrl(suffix = '') {
			return generateUrl(
				`/apps/openregister/api/objects/${this.register}/${this.schema}/${this.id}${suffix}`,
			)
		},

		/**
		 * Load the case object from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/exam-board/spec.md#requirement-frontend-is-declarative-with-one-shared-custom-detail-view
		 */
		async loadCase() {
			this.loading = true
			this.error = null
			try {
				const resp = await fetch(this.objectUrl(), {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})
				if (!resp.ok) {
					throw new Error(`Case fetch failed: ${resp.status}`)
				}
				const json = await resp.json()
				this.caseObject = json.object ?? json ?? null
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to load case. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[ExamCaseDossierView] loadCase error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Dispatch a lifecycle transition against this case, with an optional payload.
		 *
		 * @param {string} action Transition name (e.g. "grant", "decide").
		 * @param {object} payload Fields to submit alongside the transition (read by
		 *                         the server-side guard as part of the transitioning object).
		 * @return {Promise<void>}
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		async transition(action, payload = {}) {
			this.saving = true
			this.saveError = null
			try {
				const resp = await fetch(generateUrl(objectTransitionUrl(this.id)), {
					method: 'POST',
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ action, data: payload }),
				})
				if (!resp.ok) {
					throw new Error(`Transition '${action}' failed: ${resp.status}`)
				}
				await this.loadCase()
			} catch (err) {
				this.saveError = this.t(
					'learniq',
					'Action failed. Please check the required fields and try again.',
				)
				// eslint-disable-next-line no-console
				console.error(
					`[ExamCaseDossierView] transition(${action}) error`,
					err,
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		startAssessment() {
			return this.transition('startAssessment')
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		grantExemption() {
			return this.transition('grant', {
				decisionRationale: this.decisionRationale,
				policyReference: this.policyReference,
			})
		},

		rejectExemption() {
			return this.transition('reject', {
				decisionRationale: this.decisionRationale,
				policyReference: this.policyReference,
			})
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		withdrawExemption() {
			return this.transition('withdraw')
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		scheduleHearing() {
			return this.transition('scheduleHearing', {
				hearingDate: this.hearingDate,
			})
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		holdHearing() {
			return this.transition('holdHearing')
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		decideFraudCase() {
			const payload = {
				verdict: this.verdict,
				decisionRationale: this.decisionRationale,
			}
			if (this.verdict === 'fraud-proven') {
				payload.sanctionType = this.sanctionType
				payload.sanctionDurationMonths = this.sanctionDurationMonths
				payload.sanctionScope = this.sanctionScope
			}
			return this.transition('decide', payload)
		},

		/**
		 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
		 */
		dismissFraudCase() {
			return this.transition('dismiss')
		},
	},
}
</script>

<style scoped lang="scss">
.exam-case-dossier {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);

	&__header {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline, 8px);
	}

	&__status {
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 16px);
		background-color: var(--color-background-dark);
		font-size: 0.85em;
		text-transform: capitalize;
	}

	&__fields {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 4px var(--default-grid-baseline, 8px);

		dt {
			font-weight: bold;
		}
	}

	&__decision-form {
		display: flex;
		flex-direction: column;
		gap: 4px;
		max-width: 480px;

		textarea {
			min-height: 80px;
		}
	}

	&__actions {
		display: flex;
		gap: var(--default-grid-baseline, 8px);
	}

	&__redacted-notice {
		font-style: italic;
		color: var(--color-text-maxcontrast);
	}

	&__error,
	&__error-inline {
		color: var(--color-error);
	}
}
</style>
