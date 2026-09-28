<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LessonNoteDialog (timetabling-lesson-note).

 A teacher adds a note to one lesson, or to the same lesson in the next weeks:
 an optional topic, the text, and who reads it (learners, or the covering
 teacher only). A learniq lesson is named by its session id, a lesson from
 planninq's school timetable by its timetable reference. LessonNoteAuthorGuard
 is the server-side check that the writer teaches or covers the lesson; this
 dialog is only offered where the timetable says the caller may add a note.

 @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
-->
<template>
	<NcDialog
		:open="true"
		:name="t('learniq', 'Add note')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="lesson-note-dialog">
			<p class="lesson-note-dialog__lesson">
				{{ lessonLabel }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="topic"
				:label="t('learniq', 'Topic (optional)')"
				:maxlength="120" />

			<div class="lesson-note-dialog__field">
				<label for="lesson-note-text">{{ t('learniq', 'Note') }}</label>
				<textarea
					id="lesson-note-text"
					v-model="text"
					class="lesson-note-dialog__textarea"
					maxlength="2000"
					rows="4" />
			</div>

			<fieldset class="lesson-note-dialog__audience">
				<legend>{{ t('learniq', 'Who reads this note') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="audience"
					type="radio"
					value="learners"
					name="lesson-note-audience">
					{{ t('learniq', 'Learners and teachers') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="audience"
					type="radio"
					value="cover"
					name="lesson-note-audience">
					{{ t('learniq', 'Only the covering teacher and staff') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcSelect
				v-model="weeks"
				:inputLabel="
					t('learniq', 'Also add it to this lesson in the next weeks')
				"
				:options="weekOptions"
				:reduce="(opt) => opt.value"
				:clearable="false" />
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('learniq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit || saving"
				@click="submit">
				{{ saving ? t('learniq', 'Saving…') : t('learniq', 'Save note') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { fetchCohortTimetable } from '../api/timetable.js'
import { objectsUrl } from '../utils/customPages.js'
import { noteFor, seriesTargets } from '../utils/lessonNotes.js'

export default {
	name: 'LessonNoteDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The lesson, as the timetable endpoint returns it. */
		session: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			topic: '',
			text: '',
			audience: 'learners',
			weeks: 0,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The lesson the note is for, as one line.
		 *
		 * @return {string}
		 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
		 */
		lessonLabel() {
			const ts = Date.parse(this.session.startsAt || '')
			const when = Number.isNaN(ts)
				? ''
				: new Date(ts).toLocaleString(undefined, {
						weekday: 'long',
						day: 'numeric',
						month: 'long',
						hour: '2-digit',
						minute: '2-digit',
					})
			return [this.session.title || t('learniq', 'Untitled session'), when]
				.filter(Boolean)
				.join(', ')
		},

		/**
		 * Choices for the series: this lesson only, or up to ten weeks more.
		 *
		 * @return {Array<{value:number,label:string}>}
		 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
		 */
		weekOptions() {
			const options = [{ value: 0, label: t('learniq', 'Only this lesson') }]
			for (let count = 1; count <= 10; count++) {
				options.push({
					value: count,
					label:
						count === 1
							? t('learniq', 'The next week as well')
							: t('learniq', 'The next {count} weeks as well', {
									count,
								}),
				})
			}
			return options
		},

		/**
		 * Whether the note can be saved.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
		 */
		canSubmit() {
			return this.text.trim() !== '' && Boolean(this.session.cohortId)
		},
	},

	methods: {
		t,

		/**
		 * The lessons this note lands on: this one, plus its repeats in the
		 * chosen weeks, read from the cohort's timetable.
		 *
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
		 */
		async targets() {
			if (!this.weeks) {
				return [this.session]
			}
			const start = new Date(this.session.startsAt)
			const end = new Date(start.getTime() + (this.weeks * 7 + 1) * 86400000)
			const { sessions } = await fetchCohortTimetable(
				this.session.cohortId,
				start.toISOString(),
				end.toISOString(),
			)
			return seriesTargets(this.session, sessions, this.weeks)
		},

		/**
		 * Save the note on every target lesson.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
		 */
		async submit() {
			if (!this.canSubmit) return
			this.saving = true
			this.error = ''
			const form = {
				topic: this.topic,
				text: this.text,
				audience: this.audience,
			}
			try {
				const lessons = await this.targets()
				for (const lesson of lessons) {
					await axios.post(
						generateUrl(objectsUrl('lesson-note')),
						noteFor(lesson, form),
					)
				}
				this.$emit('saved', lessons.length)
				this.$emit('close')
			} catch {
				this.error = t(
					'learniq',
					'The note could not be saved. Only the teachers of a lesson can add a note to it.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.lesson-note-dialog {
	min-width: 380px;
	padding: 8px 4px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.lesson-note-dialog__lesson {
	margin: 0;
	font-weight: 600;
}

.lesson-note-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.lesson-note-dialog__field label,
.lesson-note-dialog__audience legend {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.lesson-note-dialog__audience {
	border: none;
	margin: 0;
	padding: 0;
}

.lesson-note-dialog__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}
</style>
