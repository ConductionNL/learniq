<!--
  LessonNextStepEditor.vue
  The next step editor in the lesson composer
  (content-adaptive-next-step-and-preview): an ordered list of rules, each
  "when this holds, go to that lesson", and the lesson that comes next when
  no rule holds. The first rule that holds wins (NextStepResolver). Targets
  are lessons of the same course; the server refuses any other
  (LessonNextStepGuard).

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
-->

<template>
	<section class="next-step-editor" :aria-label="t('learniq', 'Next step')">
		<h3 class="next-step-editor__title">
			{{ t('learniq', 'Next step') }}
		</h3>
		<p class="next-step-editor__hint">
			{{
				t(
					'learniq',
					'Send a learner to a different lesson depending on how they did. The first rule that applies wins.',
				)
			}}
		</p>

		<ol class="next-step-editor__rules">
			<li
				v-for="(rule, index) in local"
				:key="index"
				class="next-step-editor__rule">
				<label class="next-step-editor__label" :for="'nse-kind-' + index">
					{{ t('learniq', 'When') }}
				</label>
				<select
					:id="'nse-kind-' + index"
					v-model="rule.when.kind"
					class="next-step-editor__select"
					@change="changed">
					<option value="score-below">
						{{ t('learniq', 'Score below') }}
					</option>
					<option value="assessment-min-score">
						{{ t('learniq', 'Score at least') }}
					</option>
					<option value="lesson-completed">
						{{ t('learniq', 'Lesson completed') }}
					</option>
				</select>

				<template v-if="rule.when.kind === 'lesson-completed'">
					<NcSelect
						v-model="rule.when.lessonId"
						:options="lessonOptions"
						:reduce="(opt) => opt.id"
						:inputLabel="t('learniq', 'Lesson')"
						:aria-label-combobox="t('learniq', 'Lesson')"
						@update:modelValue="changed" />
				</template>
				<template v-else>
					<NcSelect
						v-model="rule.when.assessmentId"
						:options="assessmentOptions"
						:reduce="(opt) => opt.id"
						:inputLabel="t('learniq', 'Assessment')"
						:aria-label-combobox="t('learniq', 'Assessment')"
						@update:modelValue="changed" />
					<label
						class="next-step-editor__label"
						:for="'nse-score-' + index">
						{{ t('learniq', 'Score') }}
					</label>
					<input
						:id="'nse-score-' + index"
						v-model="rule.when[scoreField(rule)]"
						type="number"
						min="0"
						class="next-step-editor__score"
						@input="changed" />
				</template>

				<NcSelect
					v-model="rule.goToLessonId"
					:options="lessonOptions"
					:reduce="(opt) => opt.id"
					:inputLabel="t('learniq', 'Go to lesson')"
					:aria-label-combobox="t('learniq', 'Go to lesson')"
					@update:modelValue="changed" />

				<button
					type="button"
					class="button-vue button-vue--tertiary"
					@click="removeRule(index)">
					{{ t('learniq', 'Remove rule') }}
				</button>
			</li>
		</ol>

		<button
			type="button"
			class="button-vue button-vue--secondary"
			@click="addRule">
			{{ t('learniq', 'Add rule') }}
		</button>

		<NcSelect
			:modelValue="defaultNextLessonId"
			:options="lessonOptions"
			:reduce="(opt) => opt.id"
			:inputLabel="t('learniq', 'Otherwise go to lesson')"
			:aria-label-combobox="t('learniq', 'Otherwise go to lesson')"
			@update:modelValue="setDefault" />
	</section>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import {
	editableRules,
	emptyRule,
	serialiseRules,
} from '../../utils/lessonPreview.js'

export default {
	name: 'LessonNextStepEditor',

	components: { NcSelect },

	props: {
		/** The lesson's rules when the editor opens; edits go out through `update:rules`. */
		rules: {
			type: Array,
			required: true,
		},

		/** The lesson that comes next when no rule holds. */
		defaultNextLessonId: {
			type: String,
			default: null,
		},

		/** The course's other lessons, `{id, name}`. */
		lessons: {
			type: Array,
			default: () => [],
		},

		/** The course's assessments, `{id, title}`. */
		assessments: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:rules', 'update:defaultNextLessonId'],

	data() {
		return {
			/** A working copy, so the editor never mutates its prop. */
			local: editableRules(this.rules),
		}
	},

	computed: {
		/**
		 * The lessons a rule can go to, as select options.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		lessonOptions() {
			return this.lessons.map((l) => ({ id: l.id, label: l.name || l.id }))
		},

		/**
		 * The course tests a rule can read, as select options.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		assessmentOptions() {
			return this.assessments.map((a) => ({
				id: a.id,
				label: a.title || a.id,
			}))
		},
	},

	methods: {
		/**
		 * The score field a rule's kind uses.
		 *
		 * @param {object} rule The rule.
		 * @return {string} belowScore or minScore.
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		scoreField(rule) {
			return rule.when.kind === 'score-below' ? 'belowScore' : 'minScore'
		},

		/**
		 * Add an empty rule at the end.
		 *
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		addRule() {
			this.local.push(emptyRule())
			this.changed()
		},

		/**
		 * Remove one rule.
		 *
		 * @param {number} index The rule index.
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		removeRule(index) {
			this.local.splice(index, 1)
			this.changed()
		},

		setDefault(value) {
			this.$emit('update:defaultNextLessonId', value ?? null)
		},

		/**
		 * Tell the composer the rules changed.
		 *
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		changed() {
			this.$emit('update:rules', serialiseRules(this.local))
		},
	},
}
</script>

<style scoped>
.next-step-editor {
	margin-top: calc(var(--default-grid-baseline) * 6);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.next-step-editor__hint {
	color: var(--color-text-maxcontrast);
}

.next-step-editor__rules {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: 0;
	list-style: none;
}

.next-step-editor__rule {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.next-step-editor__score {
	width: 6em;
}
</style>
