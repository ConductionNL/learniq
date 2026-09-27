<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LessonAssistPanel: lesson-ai-assist-actions.

 The AI help inside LessonComposer: draft an outline from goals, suggest
 questions, suggest which goals the lesson covers, and (called by the
 composer per block) rewrite a text block in simpler words. Every call goes
 to hermiq's lesson-authoring delegate through src/utils/lessonAssist.js;
 learniq never calls a model itself.

 - Mounted only when hermiq is enabled (the composer checks).
 - Says next to the actions that the text goes to an AI model, and asks once
   per browser before the first call (LessonAssistNoticeDialog).
 - Emits every outline, question list and rewrite as a `draft`, which the
   composer inserts as an AI draft block the teacher keeps or discards.
 - Lists goal suggestions; the teacher adds each one (`addGoal`).
 - Collapses to a note, and emits `off`, when hermiq says the feature is
   switched off or has no such route. That state lasts for the browser
   session.

 @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
 @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
-->
<template>
	<NcNoteCard v-if="switchedOff" type="info" class="lesson-assist__off">
		{{
			t(
				'learniq',
				'AI help is switched off. An administrator can switch it on in Hermiq after your privacy officer agrees.',
			)
		}}
	</NcNoteCard>

	<section
		v-else
		class="lesson-assist"
		:aria-busy="busyAction !== '' ? 'true' : 'false'"
		aria-labelledby="lesson-assist-heading">
		<h3 id="lesson-assist-heading" class="lesson-assist__heading">
			{{ t('learniq', 'AI help') }}
		</h3>
		<p class="lesson-assist__notice">
			{{
				t(
					'learniq',
					'The lesson text goes to an AI model. Do not put pupil names or pupil data in it.',
				)
			}}
		</p>

		<div class="lesson-assist__field">
			<NcSelect
				v-model="selectedGoalIds"
				:options="goalOptions"
				:reduce="(opt) => opt.id"
				:multiple="true"
				:inputLabel="t('learniq', 'Goals for the outline and the questions')"
				:placeholder="
					goals.length === 0
						? t('learniq', 'This course has no linked goals yet')
						: ''
				" />
		</div>

		<div class="lesson-assist__field">
			<label for="lesson-assist-free-goal" class="lesson-assist__label">
				{{ t('learniq', 'Goal in your own words (optional)') }}
			</label>
			<input
				id="lesson-assist-free-goal"
				v-model="freeGoal"
				type="text"
				maxlength="300"
				class="lesson-assist__input" />
		</div>

		<div class="lesson-assist__row">
			<div class="lesson-assist__field">
				<label
					for="lesson-assist-question-count"
					class="lesson-assist__label">
					{{ t('learniq', 'Number of questions') }}
				</label>
				<input
					id="lesson-assist-question-count"
					v-model.number="questionCount"
					type="number"
					min="1"
					max="10"
					class="lesson-assist__input lesson-assist__input--narrow" />
			</div>
			<div class="lesson-assist__field">
				<label
					for="lesson-assist-reading-level"
					class="lesson-assist__label">
					{{ t('learniq', 'Reading level for simpler text') }}
				</label>
				<select
					id="lesson-assist-reading-level"
					v-model="readingLevel"
					class="lesson-assist__input lesson-assist__input--narrow">
					<option
						v-for="level in readingLevels"
						:key="level"
						:value="level">
						{{ level }}
					</option>
				</select>
			</div>
		</div>

		<div class="lesson-assist__actions">
			<NcButton
				:disabled="busyAction !== '' || outlineGoalTitles.length === 0"
				@click="runOutline">
				<template #icon>
					<NcLoadingIcon v-if="busyAction === 'outline'" :size="20" />
				</template>
				{{ t('learniq', 'Draft an outline') }}
			</NcButton>
			<NcButton
				:disabled="busyAction !== '' || lessonText === ''"
				@click="runQuestions">
				<template #icon>
					<NcLoadingIcon v-if="busyAction === 'questions'" :size="20" />
				</template>
				{{ t('learniq', 'Suggest questions') }}
			</NcButton>
			<NcButton
				:disabled="
					busyAction !== '' || lessonText === '' || goals.length === 0
				"
				@click="runGoalSuggestions">
				<template #icon>
					<NcLoadingIcon
						v-if="busyAction === 'goal-suggestions'"
						:size="20" />
				</template>
				{{ t('learniq', 'Suggest goals') }}
			</NcButton>
		</div>
		<p class="lesson-assist__hint">
			{{
				t(
					'learniq',
					'To rewrite a text block in simpler words, use the rewrite button on that block.',
				)
			}}
		</p>

		<p class="lesson-assist__message" role="status" aria-live="polite">
			{{ message }}
		</p>

		<ul v-if="suggestionRows.length > 0" class="lesson-assist__suggestions">
			<li
				v-for="row in suggestionRows"
				:key="row.id"
				class="lesson-assist__suggestion">
				<span class="lesson-assist__suggestion-title">{{ row.title }}</span>
				<span v-if="row.linked" class="lesson-assist__suggestion-linked">
					{{ t('learniq', 'Already linked') }}
				</span>
				<NcButton
					v-else
					variant="secondary"
					:aria-label="
						t('learniq', 'Add goal {title}', { title: row.title })
					"
					@click="$emit('addGoal', row.id)">
					{{ t('learniq', 'Add') }}
				</NcButton>
			</li>
		</ul>

		<LessonAssistNoticeDialog
			v-if="showNotice"
			@confirm="onNoticeConfirm"
			@cancel="onNoticeCancel" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import LessonAssistNoticeDialog from '../../dialogs/LessonAssistNoticeDialog.vue'
import {
	createLessonAssistClient,
	draftTextFromResult,
	goalIdsFromSuggestions,
	goalPayload,
	lessonTextFromBlocks,
	READING_LEVELS,
} from '../../utils/lessonAssist.js'

/** localStorage key that remembers the teacher confirmed the AI notice. */
const NOTICE_KEY = 'learniq:lesson-assist-notice-confirmed'

/**
 * Module state that outlives one lesson: hermiq said the feature is off, or
 * the notice was confirmed while storage was unavailable. A page reload
 * resets both (design.md D2, D3).
 */
const sessionState = { switchedOff: false, noticeConfirmed: false }

export default {
	name: 'LessonAssistPanel',

	components: {
		LessonAssistNoticeDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The composer's blocks; the lesson text is built from them. */
		blocks: {
			type: Array,
			required: true,
		},

		/** Candidate goals `{id, title}`, in a fixed order (course then lesson). */
		goals: {
			type: Array,
			default: () => [],
		},

		/** Goal ids the lesson already links (`Lesson.competencyIds` plus unsaved additions). */
		linkedGoalIds: {
			type: Array,
			default: () => [],
		},

		/** The course language, sent as `language`. */
		language: {
			type: String,
			default: 'nl',
		},
	},

	emits: ['draft', 'addGoal', 'off'],

	data() {
		return {
			switchedOff: sessionState.switchedOff,
			selectedGoalIds: [],
			freeGoal: '',
			questionCount: 5,
			readingLevel: 'B1',
			readingLevels: READING_LEVELS,
			busyAction: '',
			message: '',
			/** @type {string[]} Goal ids hermiq suggested in the last answer. */
			suggestedGoalIds: [],
			showNotice: false,
			/** @type {(() => Promise<void>)|null} The call waiting for the notice confirmation. */
			pendingRun: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 * @return {Array<{id: string, label: string}>} Goal picker options.
		 */
		goalOptions() {
			return this.goals.map((g) => ({ id: g.id, label: g.title }))
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 * @return {string} The lesson text an action sends.
		 */
		lessonText() {
			return lessonTextFromBlocks(this.blocks)
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 * @return {string[]} Titles of the picked goals.
		 */
		selectedGoalTitles() {
			const picked = this.goals.filter((g) =>
				this.selectedGoalIds.includes(g.id),
			)
			return goalPayload(picked).titles
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 * @return {string[]} The goal titles the outline is drafted from.
		 */
		outlineGoalTitles() {
			const free = this.freeGoal.trim()
			return free === ''
				? this.selectedGoalTitles
				: [...this.selectedGoalTitles, free]
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-a-teacher-adds-a-suggested-goal
		 * @return {Array<{id: string, title: string, linked: boolean}>} Suggestion rows.
		 */
		suggestionRows() {
			return this.suggestedGoalIds
				.map((id) => this.goals.find((g) => g.id === id))
				.filter(Boolean)
				.map((g) => ({
					id: g.id,
					title: g.title,
					linked: this.linkedGoalIds.includes(g.id),
				}))
		},
	},

	/**
	 * Build the hermiq client once per panel: axios for the session and CSRF
	 * token, generateUrl for the endpoint.
	 *
	 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
	 * @return {void}
	 */
	created() {
		this.client = createLessonAssistClient({
			post: (url, body) => axios.post(url, body),
			urlFor: (action) =>
				generateUrl('/apps/hermiq/api/lesson-authoring/{action}', {
					action,
				}),
		})
	},

	methods: {
		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 * @return {Promise<void>}
		 */
		runOutline() {
			return this.run('outline', {
				goalTitles: this.outlineGoalTitles,
				lessonText: this.lessonText,
				language: this.language,
			})
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 * @return {Promise<void>}
		 */
		runQuestions() {
			return this.run('questions', {
				lessonText: this.lessonText,
				goalTitles: this.selectedGoalTitles,
				questionCount: this.questionCount,
				language: this.language,
			})
		},

		/**
		 * Goal suggestions send every candidate goal's title, in the panel's
		 * fixed order, and keep the ids to map the answer's indexes back.
		 *
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-goal-suggestions-map-back-to-goal-ids
		 * @return {Promise<void>}
		 */
		runGoalSuggestions() {
			const payload = goalPayload(this.goals)
			return this.run(
				'goal-suggestions',
				{ lessonText: this.lessonText, goalTitles: payload.titles },
				{ goalIds: payload.ids },
			)
		},

		/**
		 * Rewrite one rich text block in simpler words. Called by the composer
		 * from the block's own rewrite button; the draft lands after that block.
		 *
		 * @param {object} block A richText block.
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 * @return {Promise<void>}
		 */
		simplifyBlock(block) {
			return this.run(
				'simplify',
				{ lessonText: block?.text ?? '', readingLevel: this.readingLevel },
				{ afterBlockId: block?.blockId ?? null },
			)
		},

		/**
		 * Whether the teacher confirmed the AI notice in this browser.
		 *
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-the-first-assist-call-asks-for-confirmation
		 * @return {boolean}
		 */
		noticeConfirmed() {
			if (sessionState.noticeConfirmed) return true
			try {
				return window.localStorage.getItem(NOTICE_KEY) === '1'
			} catch {
				return false
			}
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-the-first-assist-call-asks-for-confirmation
		 * @return {void}
		 */
		onNoticeConfirm() {
			sessionState.noticeConfirmed = true
			try {
				window.localStorage.setItem(NOTICE_KEY, '1')
			} catch {
				// Storage blocked: the confirmation lasts for this page only.
			}
			this.showNotice = false
			const pending = this.pendingRun
			this.pendingRun = null
			if (pending) pending()
		},

		/**
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-the-first-assist-call-asks-for-confirmation
		 * @return {void}
		 */
		onNoticeCancel() {
			this.showNotice = false
			this.pendingRun = null
		},

		/**
		 * Run one action: check it has what it needs, ask for the notice once,
		 * call hermiq, and handle the outcome.
		 *
		 * @param {string} action One of the four actions.
		 * @param {object} input The fields the request body is built from.
		 * @param {{afterBlockId?: string|null, goalIds?: string[]}} context Where a draft lands, or the ids for index mapping.
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
		 * @return {Promise<void>}
		 */
		async run(action, input, context = {}) {
			if (this.switchedOff || this.busyAction !== '') return
			if (!this.noticeConfirmed()) {
				this.pendingRun = () => this.run(action, input, context)
				this.showNotice = true
				return
			}

			this.busyAction = action
			this.message = ''
			try {
				const result = await this.client.run(action, input)
				this.handleResult(action, result, context)
			} finally {
				this.busyAction = ''
			}
		},

		/**
		 * Act on one classified answer (outcome classes in contract.md).
		 *
		 * @param {string} action The action that ran.
		 * @param {{outcome: string, data: object|null}} result The classified answer.
		 * @param {{afterBlockId?: string|null, goalIds?: string[]}} context From run().
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
		 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 * @return {void}
		 */
		handleResult(action, result, context) {
			switch (result.outcome) {
				case 'off':
					sessionState.switchedOff = true
					this.switchedOff = true
					this.$emit('off')
					return
				case 'busy':
					this.message = this.t(
						'learniq',
						'Too many requests. Wait a minute and try again.',
					)
					return
				case 'retry':
					this.message = this.t(
						'learniq',
						'The AI model gave no usable answer. Try again later.',
					)
					return
				case 'ok':
					break
				default:
					this.message = this.t(
						'learniq',
						'The AI help could not run. Try again later.',
					)
					return
			}

			if (action === 'goal-suggestions') {
				this.suggestedGoalIds = goalIdsFromSuggestions(
					result.data.suggestedGoals,
					context.goalIds ?? [],
				)
				this.message =
					this.suggestedGoalIds.length === 0
						? this.t(
								'learniq',
								'No goal from the list fits this lesson.',
							)
						: this.t(
								'learniq',
								'Suggested goals are listed below. Add the ones that fit.',
							)
				return
			}

			this.$emit('draft', {
				text: draftTextFromResult(action, result.data),
				action,
				provider: result.data.provider ?? null,
				afterBlockId: context.afterBlockId ?? null,
			})
			this.message = this.t(
				'learniq',
				'An AI draft was added. Check it, then keep or discard it.',
			)
		},
	},
}
</script>

<style scoped>
.lesson-assist {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-assist__off {
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
}

.lesson-assist__heading {
	margin: 0;
}

.lesson-assist__notice,
.lesson-assist__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.lesson-assist__field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}

.lesson-assist__row,
.lesson-assist__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-assist__label {
	font-weight: 500;
}

.lesson-assist__input {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
}

.lesson-assist__input--narrow {
	max-width: 12em;
}

.lesson-assist__suggestions {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}

.lesson-assist__suggestion {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-assist__suggestion-title {
	flex: 1 1 auto;
}

.lesson-assist__suggestion-linked {
	color: var(--color-text-maxcontrast);
}
</style>
