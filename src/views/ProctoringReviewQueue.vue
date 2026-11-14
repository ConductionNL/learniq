<!--
  ProctoringReviewQueue.vue
  Custom page component for the ProctoringReviewQueue manifest page (type: custom).

  Staff review queue for proctoring flags (board LqProctoring). Lists the
  ProctoringSession objects with at least one open flag, one card per session,
  one row per flag, and lets an instructor or compliance officer allow or annul
  each open flag once.

  EU AI Act Art. 14: a decision is recorded here and never changes the
  AssessmentResult by itself. The page sends only the decision; the server
  (ProctoringFlagReviewGuard) stamps who decided and when.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
-->

<template>
	<div class="proctoring-review-queue lq-board-page">
		<header class="proctoring-review-queue__header">
			<CnPageHeader :title="t('learniq', 'Proctoring: review flags')" />
			<p
				v-if="!loading && !error && sessionsWithPendingFlags.length > 0"
				class="proctoring-review-queue__count">
				{{
					n(
						'learniq',
						'%n session with open flags',
						'%n sessions with open flags',
						sessionsWithPendingFlags.length,
					)
				}}
			</p>
			<p class="proctoring-review-queue__subtitle">
				{{
					t(
						'learniq',
						'Review flagged proctoring events. Decisions are recorded for compliance; no result is altered automatically (EU AI Act Art. 14).',
					)
				}}
			</p>
		</header>

		<!-- Loading -->
		<div
			v-if="loading"
			class="proctoring-review-queue__loading"
			aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading sessions...') }}</span>
		</div>

		<!-- Error -->
		<div v-else-if="error" class="proctoring-review-queue__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<!-- Empty -->
		<div
			v-else-if="sessionsWithPendingFlags.length === 0"
			class="proctoring-review-queue__empty"
			role="status">
			<span class="icon-checkmark" aria-hidden="true" />
			<p>{{ t('learniq', 'No sessions with pending flags.') }}</p>
		</div>

		<!-- Session list -->
		<template v-else>
			<ul class="proctoring-review-queue__sessions">
				<li
					v-for="session in sessionsWithPendingFlags"
					:key="session.uuid"
					class="proctoring-review-queue__session">
					<CnDetailCard
						:title="
							t('learniq', 'Session for {assessment}', {
								assessment: assessmentTitle(session),
							})
						">
						<template #actions>
							<span class="proctoring-review-queue__pending-count">
								{{
									n(
										'learniq',
										'%n open flag',
										'%n open flags',
										pendingCount(session),
									)
								}}
							</span>
						</template>
						<p class="proctoring-review-queue__meta">
							{{
								t('learniq', 'Learner: {name} · provider: {provider}', {
									name: nameOf(session.learnerId),
									provider: session.provider || '',
								})
							}}
						</p>

						<!-- Flags -->
						<ul class="proctoring-review-queue__flags">
							<li
								v-for="flag in session.flags"
								:key="flag.flagId"
								class="proctoring-review-queue__flag">
								<div class="proctoring-review-queue__flag-info">
									<span class="proctoring-review-queue__flag-kind">{{
										flagKindLabel(flag.kind)
									}}</span>
									<span class="proctoring-review-queue__flag-time">{{
										flagTime(session, flag)
									}}</span>
								</div>

								<div class="proctoring-review-queue__flag-decision">
									<template v-if="isOpen(flag)">
										<button
											class="button-vue"
											:disabled="savingFlagId === flag.flagId"
											@click="
												recordDecision(session, flag, 'allowed')
											">
											<span
												v-if="savingFlagId === flag.flagId"
												class="icon-loading"
												aria-hidden="true" />
											{{ t('learniq', 'Allow') }}
										</button>
										<button
											class="button-vue button-vue--error"
											:disabled="savingFlagId === flag.flagId"
											@click="
												recordDecision(session, flag, 'annulled')
											">
											{{ t('learniq', 'Annul') }}
										</button>
									</template>
									<span
										v-else
										class="proctoring-review-queue__flag-decided"
										:class="
											'proctoring-review-queue__flag-decided--'
											+ flag.reviewDecision
										">
										{{ decisionLine(flag) }}
									</span>
								</div>
							</li>
						</ul>

						<p
							v-if="sessionError[session.uuid]"
							role="alert"
							class="proctoring-review-queue__error-inline">
							{{ sessionError[session.uuid] }}
						</p>
					</CnDetailCard>
				</li>
			</ul>

			<!-- Human-oversight reminder -->
			<p class="proctoring-review-queue__oversight-note">
				{{
					t(
						'learniq',
						'Annulling a flag does not invalidate the result automatically. Use the assessment result page for a next step.',
					)
				}}
			</p>
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { displayDateTime } from '../utils/displayDateTime.js'
import { createLearnerNameResolver } from '../utils/learnerName.js'
import {
	minutesIn,
	nameOrId,
	openFlagCount,
	withDecision,
} from '../utils/proctoringFlags.js'

const HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

/**
 * Fetch a URL and resolve to its JSON body.
 *
 * @param {string} url The URL.
 * @return {Promise<object>} The body.
 */
async function getJson(url) {
	const resp = await fetch(url, { headers: HEADERS })
	if (!resp.ok) {
		throw new Error(`Fetch failed: ${resp.status}`)
	}
	return resp.json()
}

export default {
	name: 'ProctoringReviewQueue',
	components: { CnDetailCard, CnPageHeader },

	data() {
		return {
			/** @type {object[]} */
			sessions: [],
			/** @type {Record<string, object>} The assessment result of each session, by result uuid. */
			results: {},
			/** @type {Record<string, string>} Assessment titles by assessment uuid. */
			assessments: {},
			/** @type {Record<string, string>} Display names by Nextcloud user id. */
			names: {},
			loading: false,
			error: null,
			/** @type {string|null} */
			savingFlagId: null,
			/** @type {Record<string, string>} */
			sessionError: {},
		}
	},

	computed: {
		/**
		 * Sessions that have at least one open flag.
		 *
		 * @return {object[]}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		sessionsWithPendingFlags() {
			return this.sessions.filter((s) => this.pendingCount(s) > 0)
		},
	},

	created() {
		this.loadSessions()
	},

	methods: {
		/**
		 * Fetch the ProctoringSession objects, then what their cards name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		async loadSessions() {
			this.loading = true
			this.error = null

			try {
				const json = await getJson(
					generateUrl(
						'/apps/openregister/api/objects/learniq/proctoring-session?_limit=100',
					),
				)
				this.sessions = json.results ?? json.objects ?? json ?? []
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to load proctoring sessions. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[ProctoringReviewQueue] loadSessions error', err)
			} finally {
				this.loading = false
			}

			this.loadContext()
		},

		/**
		 * Resolve the assessment results, assessment titles and people the
		 * open sessions name (design D3). A lookup that fails leaves the id.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		async loadContext() {
			const sessions = this.sessionsWithPendingFlags
			const people = new Set()
			for (const session of sessions) {
				people.add(session.learnerId)
				for (const flag of session.flags ?? []) {
					people.add(flag.reviewedBy)
				}
			}

			await Promise.all([
				...sessions.map((session) => this.loadResult(session.assessmentResultId)),
				...[...people].filter(Boolean).map((uid) => this.loadName(uid)),
			])
		},

		/**
		 * Load one assessment result and, when it carries no title, its assessment.
		 *
		 * @param {string} resultId The result uuid.
		 * @return {Promise<void>}
		 */
		async loadResult(resultId) {
			if (!resultId || this.results[resultId]) {
				return
			}
			try {
				const result = await getJson(
					generateUrl('/apps/openregister/api/objects/learniq/assessment-result/{id}', { id: resultId }),
				)
				this.results = { ...this.results, [resultId]: result }
				const assessmentId = result.assessmentId
				if (!result.assessmentTitle && assessmentId && !this.assessments[assessmentId]) {
					const assessment = await getJson(
						generateUrl('/apps/openregister/api/objects/learniq/exam/{id}', { id: assessmentId }),
					)
					this.assessments = { ...this.assessments, [assessmentId]: assessment.title || '' }
				}
			} catch {
				// The card shows the id instead (design D3).
			}
		},

		/**
		 * Resolve one person's display name: a learner by their profile, staff by
		 * their account. A failed lookup leaves the user id.
		 *
		 * @param {string} uid The Nextcloud user id.
		 * @return {Promise<void>}
		 */
		async loadName(uid) {
			let name = ''
			const current = getCurrentUser()
			if (current && current.uid === uid) {
				name = current.displayName || ''
			}
			if (name === '') {
				name = await this.learnerNames().nameOf({ ref: '', userId: uid })
			}
			if (name === '' || name === uid) {
				try {
					const body = await getJson(generateOcsUrl('cloud/users/{uid}', { uid }) + '?format=json')
					name = body?.ocs?.data?.displayname || name
				} catch {
					// Keep what there is.
				}
			}
			this.names = { ...this.names, [uid]: name || uid }
		},

		/**
		 * The page's learner-name resolver, shared by every lookup.
		 *
		 * @return {{nameOf: (lookup: {ref: string, userId: string}) => Promise<string>}}
		 */
		learnerNames() {
			if (!this.$options.learnerResolver) {
				this.$options.learnerResolver = createLearnerNameResolver({ getJson, urlFor: generateUrl })
			}
			return this.$options.learnerResolver
		},

		/**
		 * The name to show for a user id; the id until the name is known.
		 *
		 * @param {string} uid The Nextcloud user id.
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		nameOf(uid) {
			return nameOrId(this.names, uid)
		},

		/**
		 * The title of the assessment a session belongs to; the result id when it cannot be found.
		 *
		 * @param {object} session ProctoringSession object
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		assessmentTitle(session) {
			const result = this.results[session.assessmentResultId]
			const title = result?.assessmentTitle || (result ? this.assessments[result.assessmentId] : '')
			return title || session.assessmentResultId || ''
		},

		/**
		 * Count open flags on a session.
		 *
		 * @param {object} session ProctoringSession object
		 * @return {number}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		pendingCount(session) {
			return openFlagCount(session)
		},

		/**
		 * Whether a flag still waits for a decision.
		 *
		 * @param {object} flag The flag
		 * @return {boolean}
		 */
		isOpen(flag) {
			return (flag.reviewDecision ?? 'pending') === 'pending'
		},

		/**
		 * A flag kind in words (design D5). An unknown kind, such as an external
		 * provider's own, is shown as sent.
		 *
		 * @param {string} kind Flag kind value
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		flagKindLabel(kind) {
			const labels = {
				'tab-hidden': this.t('learniq', 'Switched tab or minimised the window'),
				'fullscreen-exit': this.t('learniq', 'Left full screen'),
				'window-blur': this.t('learniq', 'Window lost focus'),
				'concurrent-session-detected': this.t('learniq', 'Opened in a second tab or window'),
				'blocked-navigation': this.t('learniq', 'Tried to leave the test page'),
			}
			return labels[kind] ?? kind
		},

		/**
		 * When a flag came: the time, and the minutes into the attempt when the
		 * result says when it started (design D4).
		 *
		 * @param {object} session ProctoringSession object
		 * @param {object} flag    The flag
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		flagTime(session, flag) {
			const time = this.formatDate(flag.occurredAt)
			const minutes = minutesIn(flag.occurredAt, this.results[session.assessmentResultId]?.startedAt)
			if (minutes === null) {
				return time
			}
			return this.n('learniq', '{time}, after %n minute', '{time}, after %n minutes', minutes, { time })
		},

		/**
		 * The line a decided flag shows instead of its buttons.
		 *
		 * @param {object} flag The flag
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		decisionLine(flag) {
			const vars = { name: this.nameOf(flag.reviewedBy), date: this.formatDate(flag.reviewedAt) }
			if (flag.reviewDecision === 'allowed') {
				return this.t('learniq', 'Allowed by {name} on {date}', vars)
			}
			return this.t('learniq', 'Annulled by {name} on {date}', vars)
		},

		/**
		 * Format a datetime string in the reader's language.
		 *
		 * @param {string} dt ISO datetime string
		 * @return {string}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		formatDate(dt) {
			return displayDateTime(dt)
		},

		/**
		 * Record a decision on one flag. Only the decision is sent; the server
		 * stamps who and when, and its answer is what the page shows next.
		 * NEVER touches the associated AssessmentResult (EU AI Act Art. 14).
		 *
		 * @param {object} session    ProctoringSession object
		 * @param {object} flag       The flag to decide on
		 * @param {string} decision   'allowed' or 'annulled'
		 * @return {Promise<void>}
		 * @spec openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page
		 */
		async recordDecision(session, flag, decision) {
			this.savingFlagId = flag.flagId
			this.sessionError = { ...this.sessionError, [session.uuid]: null }

			try {
				const flags = withDecision(session.flags, flag.flagId, decision)
				const url = generateUrl(
					`/apps/openregister/api/objects/learniq/proctoring-session/${session.uuid}`,
				)
				const resp = await fetch(url, {
					method: 'PATCH',
					headers: { ...HEADERS, 'Content-Type': 'application/json' },
					body: JSON.stringify({ flags }),
				})

				if (!resp.ok) {
					throw new Error(`Flag decision save failed: ${resp.status}`)
				}

				const saved = await resp.json().catch(() => null)
				const stored = Array.isArray(saved?.flags) ? saved.flags : flags
				this.sessions = this.sessions.map((s) =>
					s.uuid === session.uuid ? { ...s, flags: stored } : s,
				)
				const reviewer = stored.find((f) => f.flagId === flag.flagId)?.reviewedBy
				if (reviewer && !this.names[reviewer]) {
					this.loadName(reviewer)
				}
			} catch (err) {
				this.sessionError = {
					...this.sessionError,
					[session.uuid]: this.t(
						'learniq',
						'Failed to save review decision. Please try again.',
					),
				}
				// eslint-disable-next-line no-console
				console.error('[ProctoringReviewQueue] recordDecision error', err)
			} finally {
				this.savingFlagId = null
			}
		},
	},
}
</script>

<style scoped>

.proctoring-review-queue__count {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.proctoring-review-queue__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.proctoring-review-queue__loading,
.proctoring-review-queue__error,
.proctoring-review-queue__empty {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.proctoring-review-queue__sessions {
	display: flex;
	flex-direction: column;
	gap: var(--cn-board-section-gap, 16px);
	margin: 0;
	list-style: none;
	padding: 0;
}

.proctoring-review-queue__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.proctoring-review-queue__pending-count {
	font-weight: bold;
	color: var(--color-warning);
}

.proctoring-review-queue__flags {
	margin: 0;
	list-style: none;
	padding: 0;
}

.proctoring-review-queue__flag {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
}

.proctoring-review-queue__flag--high {
	border-left: 4px solid var(--color-error);
}

.proctoring-review-queue__flag--medium {
	border-left: 4px solid var(--color-warning);
}

.proctoring-review-queue__flag--low {
	border-left: 4px solid var(--color-info);
}

.proctoring-review-queue__flag-info {
	flex: 1;
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	font-size: 0.9em;
}

.proctoring-review-queue__flag-kind {
	font-weight: 500;
}

.proctoring-review-queue__flag-severity {
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	font-size: 0.8em;
}

.proctoring-review-queue__flag-time {
	color: var(--color-text-maxcontrast);
}

.proctoring-review-queue__flag-decision {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	align-items: center;
	flex-shrink: 0;
}

.proctoring-review-queue__flag-decided {
	font-weight: 500;
}

.proctoring-review-queue__flag-decided--allowed {
	color: var(--color-success);
}

.proctoring-review-queue__flag-decided--annulled {
	color: var(--color-error);
}

.proctoring-review-queue__flag-reviewer {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.proctoring-review-queue__oversight-note {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.proctoring-review-queue__error-inline {
	margin: var(--default-grid-baseline, 8px)
		calc(var(--default-grid-baseline, 8px) * 2);
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
