/**
 * SCORM 1.2 `window.API` runtime — the classic 8-function RTE3 surface a
 * packaged SCO calls via `findAPI()` (walking up parent/opener frames), over
 * an in-memory CMI data model keyed by the dotted names the spec itself uses
 * (`cmi.core.lesson_status`, `cmi.core.score.raw`, `cmi.suspend_data`, ...).
 *
 * Closes finding 5.6 ("Content runtime: SCORM, cmi5, xAPI player") — learniq
 * declares `Lesson.contentType: scorm12` and stores xAPI statements, but
 * `src/views/LessonPlayer.vue` had no runtime producing them (`git grep`
 * confirmed zero `window.API`/SCORM references anywhere in `src/`).
 *
 * Legitimate imperative code per ADR-031's "External-system contract" —
 * SCORM's `window.API` is a fixed external JS calling convention a packaged
 * SCO invokes directly; it cannot be expressed as a declarative OpenRegister
 * annotation (same rationale `lib/Proctoring/ProvidesProctoring.php`'s own
 * docblock already cites for a comparable third-party protocol bridge).
 *
 * `createScorm12Api()` returns a plain object — it does NOT assign itself to
 * `window.API`. The caller (`LessonPlayer.vue`) decides exactly when to
 * mount/unmount it, so a learner navigating between two SCORM lessons never
 * leaks a stale global (design.md Decision 1).
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without an SFC compile step, same pattern as
 * `src/utils/courseOrder.js`.
 *
 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** SCORM 1.2 `cmi.core.lesson_status` values that mean the attempt is over. */
const TERMINAL_STATUSES = Object.freeze(['completed', 'passed', 'failed'])

/** xAPI verb IRIs `XapiCompletionHandler` already recognises (lib/Lifecycle/XapiCompletionHandler.php). */
const XAPI_VERBS = Object.freeze({
	completed: 'http://adlnet.gov/expapi/verbs/completed',
	passed: 'http://adlnet.gov/expapi/verbs/passed',
	// SCORM has no direct xAPI "failed" verb in the completion-recognised set;
	// a failed attempt still completes the attempt, so it maps to `completed`
	// (never silently dropped) with success:false carried in the result.
	failed: 'http://adlnet.gov/expapi/verbs/completed',
})

/** SCORM 1.2 error codes this shim uses (a small, spec-defined subset). */
const ERROR_NONE = '0'
const ERROR_GENERAL = '101'
const ERROR_NOT_INITIALIZED = '301'

/**
 * Build an xAPI 1.0.3 statement shape for a terminal SCORM lesson_status.
 * Pure function — no network call, no DOM access.
 *
 * @param {{lessonStatus: string, scoreRaw?: number|null, actorAccountName: string, activityId: string}} params
 *  `lessonStatus` MUST be one of TERMINAL_STATUSES. `actorAccountName` is the
 *  learner's account identifier (the actor is later verified/stamped
 *  server-side per XapiCompletionHandler's trust boundary — this shape is the
 *  CLIENT's claim, not the trusted one). `activityId` identifies the lesson.
 * @return {object} An xAPI statement object matching the XapiStatement schema's required fields.
 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
 */
export function buildScorm12CompletionStatement({
	lessonStatus,
	scoreRaw = null,
	actorAccountName,
	activityId,
}) {
	if (!TERMINAL_STATUSES.includes(lessonStatus)) {
		throw new Error(
			`buildScorm12CompletionStatement: "${lessonStatus}" is not a terminal SCORM 1.2 lesson_status`,
		)
	}
	const now = new Date().toISOString()
	const result = {
		completion: true,
		success: lessonStatus !== 'failed',
	}
	if (typeof scoreRaw === 'number' && Number.isFinite(scoreRaw)) {
		result.score = { raw: scoreRaw }
	}
	return {
		actor: {
			objectType: 'Agent',
			account: { name: actorAccountName },
		},
		verb: {
			id: XAPI_VERBS[lessonStatus],
			display: { en: lessonStatus },
		},
		object: {
			objectType: 'Activity',
			id: activityId,
			definition: {
				extensions: {
					'https://learniq.conduction.nl/xapi/extensions/scorm-lesson-status':
						lessonStatus,
				},
			},
		},
		result,
		timestamp: now,
		stored: now,
		version: '1.0.3',
	}
}

/**
 * Build a SCORM 1.2 `window.API` object: the 8 required RTE3 functions over
 * an in-memory CMI data model keyed by SCORM's own dotted names.
 *
 * @param {{onCompletion?: (statement: object) => void, actorAccountName: string, activityId: string}} options
 *  `onCompletion` fires at most once per shim instance, the first time a
 *  terminal `cmi.core.lesson_status` is `LMSSetValue`'d OR `LMSFinish` is
 *  called with a terminal status already set (design.md Decision 3 — fire
 *  on the earliest signal, not the latest, so a package that crashes right
 *  after setting status still gets recorded).
 * @return {{LMSInitialize: () => string, LMSFinish: () => string, LMSGetValue: (key: string) => string, LMSSetValue: (key: string, value: string) => string, LMSCommit: () => string, LMSGetLastError: () => string, LMSGetErrorString: (errorCode: string) => string, LMSGetDiagnostic: (errorCode: string) => string, getCmiValue: (key: string) => (string|undefined)}}
 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
 */
export function createScorm12Api(options = {}) {
	const {
		onCompletion = () => {},
		actorAccountName = '',
		activityId = '',
	} = options

	/** @type {Map<string, string>} */
	const cmi = new Map([
		['cmi.core.lesson_status', 'not attempted'],
		['cmi.core.score.raw', ''],
		['cmi.suspend_data', ''],
		['cmi.core.session_time', '00:00:00'],
	])

	let initialized = false
	let finished = false
	let completionFired = false
	let lastError = ERROR_NONE

	/**
	 * Fire onCompletion exactly once, the first time a terminal status is seen.
	 *
	 * @return {void}
	 */
	function maybeFireCompletion() {
		if (completionFired) {
			return
		}
		const status = cmi.get('cmi.core.lesson_status')
		if (!TERMINAL_STATUSES.includes(status)) {
			return
		}
		completionFired = true
		const rawScore = cmi.get('cmi.core.score.raw')
		const scoreRaw = rawScore === '' ? null : Number(rawScore)
		onCompletion(
			buildScorm12CompletionStatement({
				lessonStatus: status,
				scoreRaw: Number.isFinite(scoreRaw) ? scoreRaw : null,
				actorAccountName,
				activityId,
			}),
		)
	}

	return {
		/**
		 * @return {string} `"true"` on success, `"false"` if already initialized.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSInitialize() {
			if (initialized) {
				lastError = ERROR_GENERAL
				return 'false'
			}
			initialized = true
			lastError = ERROR_NONE
			return 'true'
		},

		/**
		 * @return {string} `"true"` on success, `"false"` if never initialized.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSFinish() {
			if (!initialized) {
				lastError = ERROR_NOT_INITIALIZED
				return 'false'
			}
			finished = true
			lastError = ERROR_NONE
			maybeFireCompletion()
			return 'true'
		},

		/**
		 * @param {string} key A dotted CMI element name.
		 * @return {string} The stored value, or `""` if unset/not initialized.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSGetValue(key) {
			if (!initialized) {
				lastError = ERROR_NOT_INITIALIZED
				return ''
			}
			lastError = ERROR_NONE
			return cmi.has(key) ? cmi.get(key) : ''
		},

		/**
		 * @param {string} key A dotted CMI element name.
		 * @param {string} value The value to store.
		 * @return {string} `"true"` on success, `"false"` if not initialized.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSSetValue(key, value) {
			if (!initialized || finished) {
				lastError = ERROR_NOT_INITIALIZED
				return 'false'
			}
			cmi.set(key, String(value))
			lastError = ERROR_NONE
			if (key === 'cmi.core.lesson_status') {
				maybeFireCompletion()
			}
			return 'true'
		},

		/**
		 * @return {string} `"true"` — this shim persists nothing server-side on
		 *  commit itself; persistence happens via the xAPI statement POST on
		 *  completion (onCompletion), matching this app's existing
		 *  xAPI-sourced-progress convention rather than a parallel CMI-data
		 *  persistence path.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSCommit() {
			if (!initialized) {
				lastError = ERROR_NOT_INITIALIZED
				return 'false'
			}
			lastError = ERROR_NONE
			return 'true'
		},

		/**
		 * @return {string} The last error code.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSGetLastError() {
			return lastError
		},

		/**
		 * @param {string} errorCode An error code.
		 * @return {string} A human-readable description.
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSGetErrorString(errorCode) {
			switch (errorCode) {
				case ERROR_NONE:
					return 'No error'
				case ERROR_NOT_INITIALIZED:
					return 'Not initialized'
				default:
					return 'General error'
			}
		},

		/**
		 * @param {string} errorCode An error code.
		 * @return {string} Diagnostic text (unused by this shim beyond the error string).
		 * @spec openspec/changes/lesson-player-runtime/specs/course-management/spec.md#scenario-a-scorm-12-packages-completion-status-produces-a-recognised-xapi-statement
		 */
		LMSGetDiagnostic(errorCode) {
			return this.LMSGetErrorString(errorCode)
		},

		/**
		 * Test/debug seam — not part of the SCORM 1.2 API surface itself.
		 *
		 * @param {string} key A dotted CMI element name.
		 * @return {string|undefined} The raw stored value.
		 */
		getCmiValue(key) {
			return cmi.get(key)
		},
	}
}
