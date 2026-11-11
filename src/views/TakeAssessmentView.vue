<!--
  TakeAssessmentView.vue
  Custom page component for the TakeAssessmentView manifest page (type: custom).

  Timed test-taking surface for a learner to attempt an Assessment:
  1. Fetch the Assessment (title, timeLimitMinutes, proctoring config).
  2. Create (or resume) an AssessmentResult in `in-progress` state, then re-fetch
     it by id — AssessmentDrawResolver (server-side) resolves and persists
     `drawnItemRefs`, the frozen item set/order/answer-option-order this
     attempt presents, regardless of fixed/random-draw or shuffle settings.
  3. If the Assessment is proctored, show a placeholder notice (no concrete adapter ships).
  4. Render each Item from drawnItemRefs one at a time (choice options in
     drawnItemRefs[].optionOrder when present); record the learner's response.
  5. On submit: POST responses to the AssessmentResult and dispatch the `submit` transition
     (which triggers AssessmentScoringHandler auto-scoring on the OR side).

  Uses Options API + direct fetch calls (no custom Pinia store modules).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
  @spec openspec/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
-->

<template>
	<div class="take-assessment lq-board-page">
		<!-- Loading -->
		<div v-if="loading" class="take-assessment__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading assessment…') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="take-assessment__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Closed: outside the availability window or otherwise not released -->
		<div v-else-if="closedMessage" class="take-assessment__closed" role="status">
			<span class="icon-info" aria-hidden="true" />
			<p>{{ closedMessage }}</p>
		</div>

		<!-- Access code: the assessment is behind a code (learniq#946) -->
		<form
			v-else-if="showAccessCodeForm"
			class="take-assessment__access-code"
			@submit.prevent="submitAccessCode">
			<h3>{{ assessment ? assessment.title : '' }}</h3>
			<p id="take-assessment-access-code-hint">
				{{
					t(
						'learniq',
						'Enter the access code you were given to start this assessment.',
					)
				}}
			</p>
			<label for="take-assessment-access-code">
				{{ t('learniq', 'Access code') }}
			</label>
			<input
				id="take-assessment-access-code"
				v-model="accessCode"
				type="password"
				autocomplete="off"
				aria-describedby="take-assessment-access-code-hint"
				required />
			<p
				v-if="accessCodeError"
				role="alert"
				class="take-assessment__error-inline">
				{{ accessCodeError }}
			</p>
			<button type="submit" class="button-vue button-vue--primary">
				{{ t('learniq', 'Start assessment') }}
			</button>
		</form>

		<!-- Confirmation -->
		<div
			v-else-if="submitted"
			class="take-assessment__confirmation"
			role="status"
			aria-live="polite">
			<span class="icon-checkmark" aria-hidden="true" />
			<h2>{{ t('learniq', 'Assessment submitted!') }}</h2>
			<p>
				{{
					t(
						'learniq',
						'Your responses have been recorded. Auto-scored items are marked immediately. Essay items will be reviewed by your teacher.',
					)
				}}
			</p>
		</div>

		<!-- Proctoring notice (when proctored but no adapter installed) -->
		<div
			v-else-if="showProctoringNotice"
			class="take-assessment__proctoring-notice"
			role="status">
			<span class="icon-warning" aria-hidden="true" />
			<h3>{{ t('learniq', 'Proctoring configured') }}</h3>
			<p>
				{{
					t(
						'learniq',
						'This assessment is configured with proctoring (provider: {provider}). No proctoring adapter is installed — the assessment will proceed without live proctoring. Contact your administrator.',
						{ provider: assessment.proctoring.provider },
					)
				}}
			</p>
			<button
				class="button-vue button-vue--primary"
				@click="dismissProctoringNotice">
				{{ t('learniq', 'Continue without proctoring') }}
			</button>
		</div>

		<!-- Native test-mode pre-start instructions (design §3.2) -->
		<div
			v-else-if="showTestModeIntro"
			class="take-assessment__test-mode-intro"
			role="status">
			<span class="icon-info" aria-hidden="true" />
			<h3>{{ t('learniq', 'Secure test mode') }}</h3>
			<ul class="take-assessment__test-mode-intro-list">
				<li v-if="assessment.proctoring.lockdownBrowser">
					{{
						t(
							'learniq',
							'Fullscreen is required while this test is open.',
						)
					}}
				</li>
				<li>
					{{
						t(
							'learniq',
							'Switching tabs, minimising the window, or losing focus is logged and reviewed by your teacher — nothing is acted on automatically.',
						)
					}}
				</li>
				<li>
					{{
						t(
							'learniq',
							'No camera, microphone, or screen content outside this page is captured.',
						)
					}}
				</li>
				<li>
					{{
						t(
							'learniq',
							'This test can only be open in one browser tab or window at a time.',
						)
					}}
				</li>
			</ul>
			<button
				class="button-vue button-vue--primary"
				@click="startNativeTestMode">
				{{ t('learniq', 'Start') }}
			</button>
		</div>

		<!-- Blocked: the same attempt is already open in another tab (design §3.3) -->
		<div
			v-else-if="showTabLockBlocked"
			class="take-assessment__tab-lock-blocked"
			role="alert">
			<span class="icon-error" aria-hidden="true" />
			<h3>{{ t('learniq', 'Already open in another tab') }}</h3>
			<p>
				{{
					t(
						'learniq',
						'This assessment is already open in another browser tab or window. Close this tab and continue there.',
					)
				}}
			</p>
		</div>

		<!-- Assessment header + item view -->
		<template v-else-if="assessment && resultId">
			<CnPageHeader :title="assessment.title" />
			<div class="take-assessment__meta">
				<span
					v-if="assessment.timeLimitMinutes"
					class="take-assessment__timer"
					:class="{ 'take-assessment__timer--warning': timeWarning }">
					{{
						t('learniq', 'Time remaining: {time}', {
							time: formattedTimeRemaining,
						})
					}}
				</span>
			</div>

			<!-- The items, one a page, each answer saved a moment after it is given (CnChecklistRunWidget) -->
			<CnChecklistRunWidget
				class="take-assessment__item"
				:content="runContent"
				:objectData="runRecord"
				@finished="onRunFinished" />
			<p v-if="submitting" class="take-assessment__submitting" role="status">
				<span class="icon-loading" aria-hidden="true" />
				{{ t('learniq', 'Handing in your assessment…') }}
			</p>
			<p v-if="submitError" role="alert" class="take-assessment__error-inline">
				{{ submitError }}
			</p>
		</template>
	</div>
</template>

<script>
import { CnChecklistRunWidget, CnPageHeader } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { applyAnswer, runAnswers, runContent, runQuestion, SAVE_ANSWER } from '../utils/assessmentRun.js'
import {
	answersFromResult,
	responsesPayload,
	secondsUntilDeadline,
	serverOffsetMs,
} from '../utils/attemptClock.js'
import { attemptCreateError } from '../utils/attemptRefusal.js'
import { transitionUrl } from '../utils/manualScoring.js'
import { sendJson, sendJsonOrFail } from '../utils/ocsJson.js'
import { orderChoices, parseSimpleChoices } from '../utils/qtiChoices.js'

export default {
	name: 'TakeAssessmentView',

	components: {
		CnChecklistRunWidget,
		CnPageHeader,
	},

	provide() {
		return {
			// The run widget hands each answer to this view, which keeps it on the attempt.
			cnDispatchAction: (action) => this.onRunAction(action),
		}
	},

	props: {
		/**
		 * Assessment UUID from route :assessmentId param.
		 */
		assessmentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			/** @type {object|null} */
			assessment: null,
			/** @type {object[]} */
			items: [],
			/** @type {string|null} */
			resultId: null,
			/**
			 * Full AssessmentResult object (re-fetched after create — never
			 * trusted from the POST response body, since AssessmentDrawResolver
			 * populates drawnItemRefs as a follow-up write).
			 *
			 * @type {object|null}
			 */
			result: null,
			/** @type {Record<string, unknown>} */
			responses: {},
			loading: false,
			submitting: false,
			submitted: false,
			showProctoringNotice: false,
			error: null,
			/** @type {string|null} Why the assessment cannot be started now */
			closedMessage: null,
			showAccessCodeForm: false,
			/** Access code the learner typed; sent once, on create */
			accessCode: '',
			/** @type {string|null} */
			accessCodeError: null,
			submitError: null,
			/** @type {number|null} Seconds remaining; null = untimed */
			secondsRemaining: null,
			/** @type {number|null} Interval ID */
			timerInterval: null,
			/** @type {number} Server time minus browser time, in milliseconds */
			serverOffset: 0,
			showTestModeIntro: false,
			showTabLockBlocked: false,
			nativeTestModeActive: false,
			proctoringSession: null,
			tabId: null,
			tabLockKey: null,
			tabLockInterval: null,
			lastFlagAt: {},
			nativeHandlers: {},
		}
	},

	computed: {
		/**
		 * The attempt as the run widget reads it: one question per drawn item,
		 * choices in the attempt's option order, and the answers kept so far.
		 *
		 * @return {object}
		 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
		 */
		runRecord() {
			return {
				id: this.resultId,
				questions: this.items.map((item) => runQuestion(item, {
					prompt: this.extractPrompt(item.qtiBody),
					choices: this.extractChoices(item.qtiBody, this.optionOrderFor(item)),
					help: this.helpFor(item),
				})),

				answers: runAnswers(this.responses),
			}
		},

		/**
		 * @return {object} The run widget's config.
		 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
		 */
		runContent() {
			return runContent({
				submit: this.t('learniq', 'Submit assessment'),
				saved: this.t('learniq', 'Your answers are saved'),
			})
		},

		/**
		 * Formatted time remaining string (MM:SS).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		formattedTimeRemaining() {
			if (this.secondsRemaining === null) return ''
			const m = Math.floor(this.secondsRemaining / 60)
			const s = this.secondsRemaining % 60
			return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
		},

		/**
		 * True when fewer than 5 minutes remain.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		timeWarning() {
			return this.secondsRemaining !== null && this.secondsRemaining <= 300
		},
	},

	watch: {
		assessmentId: {
			immediate: true,
			/**
			 * React to assessmentId prop changes by (re)initialising the view.
			 *
			 * @param {string} newId New assessment UUID
			 * @return {void}
			 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
			 */
			handler(newId) {
				if (newId) {
					this.init(newId)
				}
			},
		},
	},

	/**
	 * Vue lifecycle hook: stop the countdown timer and, for a native test-mode
	 * attempt, best-effort teardown (design §3.5) — detach listeners, release
	 * the tab lock, and beacon an `end` transition if the browser is closing
	 * mid-attempt without a successful submit.
	 *
	 * @return {void}
	 * @spec openspec/specs/assessment/spec.md
	 */
	beforeUnmount() {
		this.clearTimer()

		if (!this.nativeTestModeActive) return

		this.detachNativeHardening()
		this.releaseTabLock()

		// Best-effort teardown for a browser closed/navigated-away mid-attempt: a
		// synchronous fetch cannot be guaranteed to complete during unload, so use
		// sendBeacon (POST-only, which matches the transition endpoint's method)
		// where supported. If it doesn't land, the session is simply left `active`
		// with no `end` transition recorded — itself informative to a reviewer.
		if (
			!this.submitted
			&& this.proctoringSession?.uuid
			&& typeof navigator !== 'undefined'
			&& typeof navigator.sendBeacon === 'function'
		) {
			const url = generateUrl(transitionUrl(this.proctoringSession.uuid))
			const blob = new Blob([JSON.stringify({ action: 'end' })], {
				type: 'application/json',
			})
			navigator.sendBeacon(url, blob)
		}
	},

	methods: {
		/**
		 * Initialise the view: load the assessment then branch on proctoring shape
		 * (external provider notice / native test-mode intro / unproctored start).
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 * @spec openspec/specs/assessment/spec.md
		 * @spec openspec/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
		 */
		async init(id) {
			this.loading = true
			this.error = null
			this.closedMessage = null

			try {
				await this.loadAssessment(id)

				// Server-side gate facts first (learniq#946): the create guard
				// refuses an attempt outside the window or without the access
				// code anyway, this only lets the page say why up front.
				const status = await this.loadReleaseStatus(id)
				if (status && status.available === false) {
					this.closedMessage = this.closedMessageFor(
						status.reasonCode,
						status.reason,
					)
					return
				}

				if (
					status?.requiresAccessCode === true
					&& !(await this.checkExistingAttempt(id))
				) {
					this.showAccessCodeForm = true
					return
				}

				await this.proceed(id)
			} catch (err) {
				this.handleInitError(err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Continue after the gate: branch on proctoring shape (external provider
		 * notice / native test-mode intro / unproctored start).
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 * @spec openspec/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
		 */
		async proceed(id) {
			const proctoring = this.assessment?.proctoring ?? null

			if (proctoring?.provider) {
				if (proctoring?.nativeTestMode) {
					// Config error: both an external provider and native test mode are set.
					// The external provider wins (design.md §3.1) — no schema-level
					// mutual-exclusion precedent exists in this register.
					// eslint-disable-next-line no-console
					console.warn(
						'[TakeAssessmentView] Assessment.proctoring has both "provider" and "nativeTestMode" set; the external provider path wins.',
					)
				}
				this.showProctoringNotice = true
				return
			}

			if (proctoring?.nativeTestMode) {
				this.nativeTestModeActive = true
				this.tabId = this.generateId()
				this.showTestModeIntro = true
				return
			}

			// Item pools and analysis: items are resolved from the server-side
			// drawnItemRefs snapshot, which only exists once the AssessmentResult
			// has been created — loadItems() MUST run after getOrCreateResult().
			await this.getOrCreateResult(id)
			await this.loadItems()
			this.startTimer()
		},

		/**
		 * Show an init failure: a refused attempt goes back to the gate screen,
		 * a missing record is a plain message, anything else is logged.
		 *
		 * @param {Error} err The failure
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		handleInitError(err) {
			if (this.routeGateRefusal(err)) {
				return
			}
			// A missing record is input, not a fault — see the same branch in
			// PortfolioBuilder.vue. An assessment that has been withdrawn, or
			// a link a learner kept after it was removed, is an ordinary 404
			// and belongs on screen rather than in console.error.
			if (err?.notFound === true) {
				this.error = this.t(
					'learniq',
					'This assessment is no longer available, or you do not have access to it.',
				)
			} else {
				this.error = this.t(
					'learniq',
					'Failed to load assessment. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] init error', err)
			}
		},

		/**
		 * Ask the server whether this learner may start the assessment now
		 * (window, drip and release conditions) and whether it needs an access
		 * code. A failed lookup returns null: the create guard still decides.
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<object|null>} `{available, reason, reasonCode, requiresAccessCode}`
		 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
		 */
		async loadReleaseStatus(id) {
			try {
				const resp = await fetch(
					generateUrl(
						`/apps/learniq/api/assessments/${id}/release-status`,
					),
					{
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
						},
					},
				)
				if (!resp.ok) return null
				return await resp.json()
			} catch (err) {
				return null
			}
		},

		/**
		 * The sentence to show for a refused start, by the server's reason code.
		 *
		 * @param {string|null} reasonCode Machine reason from the server
		 * @param {string|null} fallback Server-supplied text for other reasons
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
		 */
		closedMessageFor(reasonCode, fallback) {
			if (reasonCode === 'window-not-open') {
				const from = this.assessment?.availableFrom
				if (from) {
					return this.t(
						'learniq',
						'This assessment is not open yet. It opens on {date}.',
						{ date: new Date(from).toLocaleString() },
					)
				}
				return this.t('learniq', 'This assessment is not open yet.')
			}
			if (reasonCode === 'window-closed') {
				return this.t('learniq', 'This assessment is closed.')
			}
			if (reasonCode === 'attempts-used') {
				return this.t(
					'learniq',
					'You have used all attempts for this assessment.',
				)
			}
			return (
				fallback
				|| this.t('learniq', 'This assessment is not available right now.')
			)
		},

		/**
		 * Route a create refused by the server's attempt gate back to the right
		 * screen. Returns false for any other failure.
		 *
		 * @param {Error} err The failure
		 * @return {boolean} True when handled
		 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
		 */
		routeGateRefusal(err) {
			const reason = err?.gateReason ?? null
			if (!reason) return false

			if (
				reason === 'access-code-required'
				|| reason === 'access-code-invalid'
			) {
				this.showProctoringNotice = false
				this.showTestModeIntro = false
				this.showAccessCodeForm = true
				this.accessCodeError =
					reason === 'access-code-invalid'
						? this.t('learniq', 'The access code is not correct.')
						: null
				return true
			}

			this.closedMessage = this.closedMessageFor(reason, err?.gateMessage)
			return true
		},

		/**
		 * The learner entered an access code: continue the normal start. The
		 * code is checked by the server when the attempt is created.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
		 */
		async submitAccessCode() {
			this.showAccessCodeForm = false
			this.accessCodeError = null
			this.loading = true
			try {
				await this.proceed(this.assessmentId)
			} catch (err) {
				this.handleInitError(err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the Assessment object.
		 *
		 * @param {string} id Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async loadAssessment(id) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/exam/${id}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				// `notFound` marks the single-object lookup — see the same guard
				// in PortfolioBuilder.vue. init() also awaits list-shaped loads,
				// and a 404 from one of those means a schema did not resolve
				// rather than that this assessment is gone.
				const err = new Error(`Assessment fetch failed: ${resp.status}`)
				err.status = resp.status
				err.notFound = resp.status === 404
				throw err
			}
			const json = await resp.json()
			this.assessment = json.object ?? json ?? {}
		},

		/**
		 * Fetch Item objects for every entry in AssessmentResult.drawnItemRefs —
		 * the frozen, server-resolved snapshot of exactly which items (and, when
		 * shuffleItemOrder is set, in which order) this attempt presents. Reads
		 * drawnItemRefs instead of Assessment.itemRefs directly, since the
		 * resolved set may differ from itemRefs (random-draw, or a shuffled
		 * fixed list) — AssessmentDrawResolver populates it for EVERY attempt.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-every-assessmentresult-persists-a-frozen-server-resolved-snapshot-of-what-was-presented
		 */
		async loadItems() {
			const drawnItemRefs = this.result?.drawnItemRefs ?? []
			const itemIds = drawnItemRefs.map((r) => r.itemId).filter(Boolean)
			if (itemIds.length === 0) {
				this.items = []
				return
			}

			// Fetch each item individually (OR REST supports single object fetch).
			const fetched = await Promise.all(
				itemIds.map(async (itemId) => {
					const url = generateUrl(
						`/apps/openregister/api/objects/learniq/Item/${itemId}`,
					)
					const resp = await fetch(url, {
						headers: {
							'OCS-APIREQUEST': 'true',
							Accept: 'application/json',
						},
					})
					if (!resp.ok) return null
					const json = await resp.json()
					return json.object ?? json ?? null
				}),
			)

			this.items = fetched.filter(Boolean)
		},

		/**
		 * Create a new AssessmentResult in `in-progress` state.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async createResult(assessmentId) {
			const body = this.newResultBody(assessmentId)
			const url = generateUrl(
				'/apps/openregister/api/objects/learniq/assessment-result',
			)
			const resp = await sendJson(url, { method: 'POST', body })
			if (!resp.ok) {
				throw await attemptCreateError(resp)
			}
			const json = await resp.json()
			const created = json.object ?? json ?? {}
			this.resultId = created.uuid ?? created.id
		},

		/**
		 * The body of a new `in-progress` AssessmentResult for the current learner.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {object}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		newResultBody(assessmentId) {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'
			return {
				assessmentId,
				learnerId,
				attemptNumber: 1,
				responses: [],
				startedAt: new Date().toISOString(),
				lifecycle: 'in-progress',
				tenant_id: this.assessment?.tenant_id ?? '',
				...(this.accessCode ? { accessCode: this.accessCode } : {}),
			}
		},

		/**
		 * Re-fetch an AssessmentResult by id (GET). MUST run before loadItems()
		 * reads drawnItemRefs — whether OR's ObjectCreatedEvent dispatch (which
		 * AssessmentDrawResolver listens on to populate drawnItemRefs) completes
		 * before the original POST response is serialized is an implementation
		 * detail this view does not assume either way, so the create response
		 * body is never trusted for drawnItemRefs.
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
		 */
		async fetchResult(resultId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/assessment-result/${resultId}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) {
				throw new Error(`AssessmentResult fetch failed: ${resp.status}`)
			}
			const json = await resp.json()
			this.result = json.object ?? json ?? {}
			this.serverOffset = serverOffsetMs(
				resp.headers?.get?.('Date'),
				Date.now(),
			)
			// A resumed attempt shows the answers autosave already stored.
			this.responses = { ...answersFromResult(this.result), ...this.responses }
		},

		/**
		 * Look up an existing non-terminal (`in-progress`) AssessmentResult for the
		 * current learner and Assessment, mirroring the established fetch-all-then-
		 * filter convention (`ProctoringReviewQueue.vue:loadSessions()`) — no field-
		 * filter query parameter is assumed to exist server-side.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<object|null>} The existing in-progress result, or null.
		 * @spec openspec/specs/assessment/spec.md
		 */
		async checkExistingAttempt(assessmentId) {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'

			const url = generateUrl(
				'/apps/openregister/api/objects/learniq/assessment-result?_limit=100',
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) return null
			const json = await resp.json()
			const results = json.results ?? json.objects ?? json ?? []
			return (
				results.find(
					(r) =>
						r.assessmentId === assessmentId
						&& r.learnerId === learnerId
						&& r.lifecycle === 'in-progress',
				) ?? null
			)
		},

		/**
		 * Single-attempt window guard: resume an existing in-progress AssessmentResult
		 * for this learner+assessment instead of creating a duplicate one.
		 *
		 * @param {string} assessmentId Assessment UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 * @spec openspec/specs/assessment/spec.md#requirement-item-draw-and-shuffle-resolution-runs-server-side-and-never-trusts-a-client-supplied-value
		 */
		async getOrCreateResult(assessmentId) {
			const existing = await this.checkExistingAttempt(assessmentId)
			if (existing) {
				this.resultId = existing.uuid ?? existing.id
			} else {
				await this.createResult(assessmentId)
			}
			// Always re-fetch by id — never trust drawnItemRefs from the create
			// response body (design.md "Frontend consequence").
			await this.fetchResult(this.resultId)
		},

		/**
		 * Generate an identifier for a flag or tab-lock, preferring crypto.randomUUID
		 * where available.
		 *
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md
		 */
		generateId() {
			if (
				typeof crypto !== 'undefined'
				&& typeof crypto.randomUUID === 'function'
			) {
				return crypto.randomUUID()
			}
			return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
		},

		/**
		 * Start the countdown timer if the assessment has a time limit.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		startTimer() {
			const deadlineAt = this.result?.deadlineAt ?? null
			const minutes = this.assessment?.timeLimitMinutes ?? null
			if (!deadlineAt && !minutes) return

			// The server's deadline counts the learner's extra time and does not
			// restart when the attempt is resumed; the browser's own count is
			// only the fallback for an attempt without one.
			const fallbackEnd = Date.now() + (minutes ?? 0) * 60 * 1000
			const remaining = () => {
				const fromServer = secondsUntilDeadline(
					deadlineAt,
					Date.now(),
					this.serverOffset,
				)
				if (fromServer !== null) return fromServer
				return Math.max(0, Math.floor((fallbackEnd - Date.now()) / 1000))
			}

			this.clearTimer()
			this.secondsRemaining = remaining()
			this.timerInterval = setInterval(() => {
				this.secondsRemaining = remaining()
				if (this.secondsRemaining <= 0) {
					this.clearTimer()
					this.submitAssessment()
				}
			}, 1000)
		},

		/**
		 * Clear the countdown timer.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		clearTimer() {
			if (this.timerInterval !== null) {
				clearInterval(this.timerInterval)
				this.timerInterval = null
			}
		},

		/**
		 * Dismiss the proctoring notice and proceed with creating the result.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async dismissProctoringNotice() {
			this.showProctoringNotice = false
			this.loading = true
			try {
				await this.getOrCreateResult(this.assessmentId)
				await this.loadItems()
				this.startTimer()
			} catch (err) {
				if (this.routeGateRefusal(err)) return
				this.error = this.t(
					'learniq',
					'Failed to start assessment. Please try again.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Learner clicked "Start" on the native test-mode pre-start screen (design
		 * §3.2): apply the single-attempt guard, acquire the same-browser tab lock,
		 * and — if not blocked — create the ProctoringSession and attach hardening.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async startNativeTestMode() {
			this.loading = true
			this.error = null
			try {
				await this.getOrCreateResult(this.assessmentId)

				const blocked = this.acquireTabLock(this.resultId)
				this.showTestModeIntro = false

				if (blocked) {
					this.showTabLockBlocked = true
					await this.flagConcurrentSessionForBlockedTab(this.resultId)
					return
				}

				await this.createProctoringSession()
				this.attachNativeHardening()
				await this.loadItems()
				this.startTimer()
			} catch (err) {
				if (this.routeGateRefusal(err)) {
					this.showTestModeIntro = false
					return
				}
				this.error = this.t(
					'learniq',
					'Failed to start assessment. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] startNativeTestMode error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Same-browser tab-lock guard (design §3.3): a localStorage heartbeat keyed
		 * by resultId. Returns true when a live lock from a different tab already
		 * holds this attempt; otherwise claims the lock and starts the heartbeat.
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {boolean} True when this tab is blocked by a live lock elsewhere.
		 * @spec openspec/specs/assessment/spec.md
		 */
		acquireTabLock(resultId) {
			const key = `learniq-native-test-mode-lock-${resultId}`
			let existing = null
			try {
				const raw = window.localStorage.getItem(key)
				existing = raw ? JSON.parse(raw) : null
			} catch {
				existing = null
			}

			const now = Date.now()
			const isLive =
				!!existing
				&& existing.tabId !== this.tabId
				&& now - (existing.updatedAt ?? 0) < 15000
			if (isLive) {
				return true
			}

			this.tabLockKey = key
			this.writeTabLock(key)
			this.tabLockInterval = setInterval(() => this.writeTabLock(key), 5000)
			return false
		},

		/**
		 * Write this tab's heartbeat entry to the tab-lock key. Best-effort —
		 * localStorage may be unavailable (private browsing quota, disabled storage).
		 *
		 * @param {string} key localStorage key for the current attempt's lock
		 * @return {void}
		 * @spec openspec/specs/assessment/spec.md
		 */
		writeTabLock(key) {
			try {
				window.localStorage.setItem(
					key,
					JSON.stringify({ tabId: this.tabId, updatedAt: Date.now() }),
				)
			} catch {
				// best-effort only
			}
		},

		/**
		 * Stop the tab-lock heartbeat and release the held lock, if any.
		 *
		 * @return {void}
		 * @spec openspec/specs/assessment/spec.md
		 */
		releaseTabLock() {
			if (this.tabLockInterval !== null) {
				clearInterval(this.tabLockInterval)
				this.tabLockInterval = null
			}
			if (this.tabLockKey) {
				try {
					window.localStorage.removeItem(this.tabLockKey)
				} catch {
					// best-effort only
				}
				this.tabLockKey = null
			}
		},

		/**
		 * Find the ProctoringSession for a blocked tab's resultId (fetch-all-then-
		 * filter, matching `ProctoringReviewQueue.vue`'s convention) and append a
		 * `concurrent-session-detected` flag when one exists (design §3.3).
		 *
		 * @param {string} resultId AssessmentResult UUID
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async flagConcurrentSessionForBlockedTab(resultId) {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/proctoring-session?_limit=100',
				)
				const resp = await fetch(url, {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})
				if (!resp.ok) return
				const json = await resp.json()
				const sessions = json.results ?? json.objects ?? json ?? []
				const session = sessions.find(
					(s) => s.assessmentResultId === resultId,
				)
				if (!session) return

				this.proctoringSession = session
				await this.appendFlag('concurrent-session-detected', 'high')
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error(
					'[TakeAssessmentView] flagConcurrentSessionForBlockedTab error',
					err,
				)
			}
		},

		/**
		 * Create the native-mode ProctoringSession (design §3.4) and dispatch its
		 * existing `activate` transition.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async createProctoringSession() {
			const resp = await this.postProctoringSession()
			const json = await resp.json()
			this.proctoringSession = json.object ?? json ?? {}

			await this.dispatchTransition(this.proctoringSession.uuid, {
				action: 'activate',
				failure: 'ProctoringSession activate transition failed',
			})
		},

		/**
		 * Store the native-mode ProctoringSession, rejecting unless it is created.
		 *
		 * @return {Promise<Response>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		postProctoringSession() {
			const body = this.newProctoringSessionBody()
			const url = generateUrl(
				'/apps/openregister/api/objects/learniq/proctoring-session',
			)
			return sendJsonOrFail(url, {
				method: 'POST',
				body,
				failure: 'ProctoringSession create failed',
			})
		},

		/**
		 * The body of the native-mode ProctoringSession for the current attempt.
		 *
		 * @return {object}
		 * @spec openspec/specs/assessment/spec.md
		 */
		newProctoringSessionBody() {
			const currentUser = getCurrentUser()
			const learnerId = currentUser?.uid ?? 'anonymous'
			return {
				assessmentResultId: this.resultId,
				learnerId,
				provider: 'native-test-mode',
				status: 'created',
				tenant_id: this.assessment?.tenant_id ?? '',
			}
		},

		/**
		 * Dispatch a lifecycle transition on an object (Open Register routes
		 * transitions at /api/objects/{id}/transition with the action in the
		 * body, learniq#948), rejecting unless it succeeds.
		 *
		 * @param {string} id The object UUID
		 * @param {{action: string, failure: string}} request The action and the error text before the status
		 * @return {Promise<Response>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		dispatchTransition(id, { action, failure }) {
			return sendJsonOrFail(generateUrl(transitionUrl(id)), {
				method: 'POST',
				body: { action },
				failure,
			})
		},

		/**
		 * Attach the native hardening listener set (design §3.4): fullscreen exit is
		 * gated by `lockdownBrowser`; tab-hidden and window-blur are always attached
		 * in native mode; popstate/beforeunload are gated by `navigationLock`.
		 *
		 * @return {void}
		 * @spec openspec/specs/assessment/spec.md
		 */
		attachNativeHardening() {
			const proctoring = this.assessment?.proctoring ?? {}
			const handlers = {}

			handlers.visibilitychange = () => {
				if (document.hidden) {
					this.appendFlag('tab-hidden', 'medium')
				}
			}
			document.addEventListener('visibilitychange', handlers.visibilitychange)

			handlers.blur = () => {
				this.appendFlag('window-blur', 'low')
			}
			window.addEventListener('blur', handlers.blur)

			if (proctoring.lockdownBrowser) {
				handlers.fullscreenchange = () => {
					if (!document.fullscreenElement) {
						this.appendFlag('fullscreen-exit', 'medium')
					}
				}
				document.addEventListener(
					'fullscreenchange',
					handlers.fullscreenchange,
				)

				if (document.documentElement.requestFullscreen) {
					document.documentElement.requestFullscreen().catch(() => {
						// Fullscreen request rejected (no user gesture, unsupported, etc.) —
						// deterrence only; the attempt still proceeds (design.md §1).
					})
				}
			}

			if (proctoring.navigationLock) {
				handlers.popstate = () => {
					this.appendFlag('blocked-navigation', 'low')
					// Re-assert a sentinel history entry: deters, does not prevent
					// (browsers do not let a page trap the back button, design.md §1).
					window.history.pushState(null, '', window.location.href)
				}
				handlers.beforeunload = (event) => {
					if (!this.submitted) {
						event.preventDefault()
						event.returnValue = ''
					}
				}
				window.history.pushState(null, '', window.location.href)
				window.addEventListener('popstate', handlers.popstate)
				window.addEventListener('beforeunload', handlers.beforeunload)
			}

			this.nativeHandlers = handlers
		},

		/**
		 * Remove every listener attached by `attachNativeHardening()`.
		 *
		 * @return {void}
		 * @spec openspec/specs/assessment/spec.md
		 */
		detachNativeHardening() {
			const handlers = this.nativeHandlers ?? {}
			if (handlers.visibilitychange)
				document.removeEventListener(
					'visibilitychange',
					handlers.visibilitychange,
				)
			if (handlers.blur) window.removeEventListener('blur', handlers.blur)
			if (handlers.fullscreenchange)
				document.removeEventListener(
					'fullscreenchange',
					handlers.fullscreenchange,
				)
			if (handlers.popstate)
				window.removeEventListener('popstate', handlers.popstate)
			if (handlers.beforeunload)
				window.removeEventListener('beforeunload', handlers.beforeunload)
			this.nativeHandlers = {}
		},

		/**
		 * Append a qualifying event as a flag on the active ProctoringSession, using
		 * the exact read-modify-write PUT pattern `ProctoringReviewQueue.vue`'s
		 * `recordDecision()` uses for the reciprocal write. Client-side throttled to
		 * one flag per `kind` per 5 seconds (design §3.4). Never alters the
		 * AssessmentResult (EU AI Act Art. 14 human oversight).
		 *
		 * @param {string} kind     Flag kind (see design §3.4 event table)
		 * @param {string} severity 'low' | 'medium' | 'high'
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async appendFlag(kind, severity) {
			if (!this.proctoringSession?.uuid) return
			if (!this.takeFlagSlot(kind)) return

			const newFlag = this.newFlag(kind, severity)
			const updatedFlags = [...(this.proctoringSession.flags ?? []), newFlag]

			try {
				await this.saveSessionFlags(updatedFlags)
				this.proctoringSession = {
					...this.proctoringSession,
					flags: updatedFlags,
				}
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error('[TakeAssessmentView] appendFlag error', err)
			}
		},

		/**
		 * Claim the flag slot of a kind: false while the last flag of that kind is
		 * under 5 seconds old, otherwise stamp now and return true.
		 *
		 * @param {string} kind Flag kind
		 * @return {boolean}
		 * @spec openspec/specs/assessment/spec.md
		 */
		takeFlagSlot(kind) {
			const now = Date.now()
			const lastAt = this.lastFlagAt[kind] ?? 0
			if (now - lastAt < 5000) return false
			this.lastFlagAt = { ...this.lastFlagAt, [kind]: now }
			return true
		},

		/**
		 * A new pending flag.
		 *
		 * @param {string} kind     Flag kind
		 * @param {string} severity 'low' | 'medium' | 'high'
		 * @return {object}
		 * @spec openspec/specs/assessment/spec.md
		 */
		newFlag(kind, severity) {
			return {
				flagId: this.generateId(),
				kind,
				occurredAt: new Date().toISOString(),
				severity,
				reviewDecision: 'pending',
			}
		},

		/**
		 * Write the flag list of the current ProctoringSession.
		 *
		 * @param {Array<object>} flags The full flag list
		 * @return {Promise<Response>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		saveSessionFlags(flags) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/proctoring-session/${this.proctoringSession.uuid}`,
			)
			return sendJsonOrFail(url, {
				method: 'PATCH',
				body: { flags },
				failure: 'Flag append failed',
			})
		},

		/**
		 * Native-mode teardown on a successful submit (design §3.5): dispatch the
		 * ProctoringSession's `end` transition, release the tab lock, exit
		 * fullscreen, and detach listeners.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async teardownNativeTestMode() {
			this.detachNativeHardening()
			this.releaseTabLock()
			await this.leaveFullscreen()

			if (this.proctoringSession?.uuid) {
				await this.endProctoringSession()
			}
		},

		/**
		 * Leave fullscreen when the page is in it; best-effort only.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async leaveFullscreen() {
			if (!document.fullscreenElement) return
			try {
				await document.exitFullscreen()
			} catch {
				// best-effort only
			}
		},

		/**
		 * Dispatch the ProctoringSession's `end` transition; a failure is logged.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md
		 */
		async endProctoringSession() {
			try {
				await this.dispatchTransition(this.proctoringSession.uuid, {
					action: 'end',
					failure: 'ProctoringSession end transition failed',
				})
			} catch (err) {
				// eslint-disable-next-line no-console
				console.error(
					'[TakeAssessmentView] teardownNativeTestMode error',
					err,
				)
			}
		},

		/**
		 * Save the answers on the attempt in progress. PATCH, so only the
		 * answers change; the server keeps them out after the deadline. A
		 * failed save rejects, so the run widget says the answer is not saved.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
		 */
		async autosave() {
			if (!this.resultId || this.submitted || this.submitting) return
			await sendJsonOrFail(
				generateUrl(`/apps/openregister/api/objects/learniq/assessment-result/${this.resultId}`),
				{
					method: 'PATCH',
					body: { responses: responsesPayload(this.items, this.responses) },
					failure: 'autosave failed',
				},
			)
		},

		/**
		 * The run widget gave an answer (its `saveEachAnswer` waited a moment
		 * after the last change): keep it and save the attempt.
		 *
		 * @param {{id: string, args?: Array<object>}} action The dispatched save action, the answer last.
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#scenario-answers-are-saved-while-the-learner-works
		 */
		onRunAction(action) {
			if (action?.id !== SAVE_ANSWER) return Promise.resolve()
			const args = Array.isArray(action.args) ? action.args : []
			this.responses = applyAnswer(this.responses, args[args.length - 1])
			return this.autosave()
		},

		/**
		 * The learner handed in from the run widget, or its clock ran out.
		 *
		 * @return {Promise<void>|undefined}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		onRunFinished() {
			if (this.submitting || this.submitted) return undefined
			return this.submitAssessment()
		},

		/**
		 * The server-resolved option order of one item, from
		 * AssessmentResult.drawnItemRefs[].optionOrder (null when shuffle is off).
		 *
		 * @param {object} item The Item.
		 * @return {string[]|null}
		 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
		 */
		optionOrderFor(item) {
			const ref = (this.result?.drawnItemRefs ?? []).find((r) => r.itemId === item.uuid)
			return ref?.optionOrder ?? null
		},

		/**
		 * The line under an item's question: how to answer it.
		 *
		 * @param {object} item The Item.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		helpFor(item) {
			if (item.interactionType === 'choice') return this.t('learniq', 'Choose one answer.')
			if (item.interactionType === 'extendedText') return this.t('learniq', 'This item will be marked by your teacher.')
			return this.t('learniq', 'Write your answer in words.')
		},

		/**
		 * Submit the assessment: persist responses then dispatch the `submit` transition.
		 * The OR-side AssessmentScoringHandler auto-scores choice/textEntry items.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async submitAssessment() {
			if (!this.resultId) return
			this.submitting = true
			this.submitError = null
			this.clearTimer()

			try {
				await this.handIn()
			} catch (err) {
				this.reportSubmitFailure(err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Tell the learner the hand-in failed, and log why.
		 *
		 * @param {Error} err The failure
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		reportSubmitFailure(err) {
			this.submitError = this.t(
				'learniq',
				'Failed to submit assessment. Please try again.',
			)
			// eslint-disable-next-line no-console
			console.error('[TakeAssessmentView] submitAssessment error', err)
		},

		/**
		 * Persist the responses, dispatch the `submit` transition (triggers
		 * AssessmentScoringHandler) and, in native mode, tear the test mode down.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		async handIn() {
			await this.saveFinalResponses()
			await this.dispatchTransition(this.resultId, {
				action: 'submit',
				failure: 'Submit transition failed',
			})

			this.submitted = true

			if (this.nativeTestModeActive) {
				await this.teardownNativeTestMode()
			}
		},

		/**
		 * Persist the responses with the hand-in time. PATCH, not PUT: a PUT
		 * replaces the object, and this body carries only the responses and
		 * submittedAt.
		 *
		 * @return {Promise<Response>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 */
		saveFinalResponses() {
			const patchUrl = generateUrl(
				`/apps/openregister/api/objects/learniq/assessment-result/${this.resultId}`,
			)
			return sendJsonOrFail(patchUrl, {
				method: 'PATCH',
				body: {
					responses: responsesPayload(this.items, this.responses),
					submittedAt: new Date().toISOString(),
				},
				failure: 'Response save failed',
			})
		},

		/**
		 * Extract choice options from a QTI choice interaction XML body.
		 * Returns an array of { id, label } objects, in `optionOrder` when given
		 * (the server-resolved AssessmentResult.drawnItemRefs[].optionOrder for
		 * this item — present when shuffleAnswerOptions is enabled), falling
		 * back to the qtiBody's declared order otherwise.
		 *
		 * @param {string} qtiBody Raw QTI XML body
		 * @param {string[]|null} [optionOrder] Server-resolved identifier order, or null.
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-27
		 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
		 */
		extractChoices(qtiBody, optionOrder = null) {
			if (!qtiBody) return []
			const choices = parseSimpleChoices(qtiBody)

			if (choices.length === 0) {
				return [
					{ id: 'a', label: 'Option A' },
					{ id: 'b', label: 'Option B' },
				]
			}

			return orderChoices(choices, optionOrder)
		},
	},
}
</script>

<style scoped>
.take-assessment__loading,
.take-assessment__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__closed {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__access-code {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	max-inline-size: 400px;
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__meta {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	margin-top: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.take-assessment__timer {
	font-weight: bold;
}

.take-assessment__timer--warning {
	color: var(--color-error);
}

.take-assessment__confirmation {
	text-align: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}

.take-assessment__proctoring-notice {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__test-mode-intro {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-info);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__test-mode-intro-list {
	margin: calc(var(--default-grid-baseline, 8px) * 2) 0;
	padding-left: calc(var(--default-grid-baseline, 8px) * 2);
}

.take-assessment__test-mode-intro-list li {
	margin-bottom: var(--default-grid-baseline, 8px);
}

.take-assessment__tab-lock-blocked {
	padding: calc(var(--default-grid-baseline, 8px) * 3);
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-error);
	border-radius: var(--border-radius, 4px);
}

.take-assessment__error-inline {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
