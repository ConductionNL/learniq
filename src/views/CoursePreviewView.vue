<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CoursePreviewView: a course author walks the course as a learner (route
 /courses/:courseId/preview, content-adaptive-next-step-and-preview).

 Lists the course's lessons in order from GET /api/courses/{id}/preview
 (403 for anyone who does not author courses), takes the simulated score the
 next step rules should see, and opens a lesson in the real lesson player in
 preview mode. The player then records nothing (design D2).

 @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#requirement-preview-as-learner
-->
<template>
	<div class="course-preview">
		<h2>{{ t('learniq', 'Preview as learner') }}</h2>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<p class="course-preview__intro">
				{{
					t(
						'learniq',
						'You see {course} as a learner does. Nothing you do in the preview is recorded.',
						{ course: courseName },
					)
				}}
			</p>
			<NcTextField
				v-model="score"
				type="number"
				:label="t('learniq', 'Simulated score')"
				:helperText="
					t(
						'learniq',
						'The next step rules treat every test in the preview as if it got this score.',
					)
				" />
			<NcEmptyContent
				v-if="lessons.length === 0"
				:name="t('learniq', 'This course has no lessons yet')" />
			<ol v-else class="course-preview__lessons">
				<li v-for="lesson in lessons" :key="lesson.id">
					<NcButton variant="tertiary" @click="open(lesson.id)">
						{{ lesson.name || lesson.id }}
					</NcButton>
				</li>
			</ol>
			<div class="course-preview__actions">
				<NcButton
					variant="primary"
					:disabled="lessons.length === 0"
					@click="open(lessons[0].id)">
					{{ t('learniq', 'Start the preview') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { previewQuery } from '../utils/lessonPreview.js'

export default {
	name: 'CoursePreviewView',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextField },

	props: {
		/** Course UUID from the route :courseId param. */
		courseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			courseName: '',
			lessons: [],
			score: '',
		}
	},

	/**
	 * Load the course's lessons for the preview.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-learner-cannot-preview
	 */
	async mounted() {
		try {
			const resp = await fetch(
				generateUrl(
					'/apps/learniq/api/courses/'
						+ encodeURIComponent(this.courseId)
						+ '/preview',
				),
				{ headers: { Accept: 'application/json' } },
			)
			if (resp.status === 403) {
				this.error = this.t(
					'learniq',
					'Only course authors can preview a course.',
				)
				return
			}
			if (!resp.ok) {
				this.error = this.t(
					'learniq',
					'Failed to load course (HTTP {status})',
					{ status: resp.status },
				)
				return
			}
			const body = await resp.json()
			this.courseName = body.name ?? ''
			this.lessons = Array.isArray(body.lessons) ? body.lessons : []
		} catch (e) {
			this.error = e?.message ?? String(e)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Open a lesson in the player in preview mode.
		 *
		 * @param {string} lessonId The lesson.
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
		 */
		open(lessonId) {
			const score =
				this.score === '' || Number.isNaN(Number(this.score))
					? null
					: Number(this.score)
			this.$router
				?.push({
					name: 'LessonPlayer',
					params: { courseId: this.courseId, lessonId },
					query: previewQuery({ active: true, score }),
				})
				.catch(() => {})
		},
	},
}
</script>

<style scoped>
.course-preview {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 720px;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.course-preview__lessons {
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}
</style>
