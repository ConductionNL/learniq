<!--
  LessonComposer.vue
  Custom page component for the LessonComposer manifest page (type: custom).

  course-authoring-ux: the per-lesson block editor. Composes a
  contentType='text' Lesson's body as an ordered list of typed blocks —
  richText (CnMarkdownEditor, inline markdown), media (pointer to an
  existing Material, or a freshly-created one via the NC file picker),
  quiz/assignment/ltiTool (NcSelect pickers against existing
  Assessment/Assignment/LtiToolPlacement objects). The list is
  CnBlockListEditor (learniq/LqLesMaken): a palette, drag-and-drop and
  Up/Down buttons that say the new position (WCAG 2.2 SC 2.1.1, 2.5.7), and
  Keep/Discard on an AI draft. Each type's editor sits in its block slot.

  Talks only to OpenRegister's REST API:
    - GET /api/objects/learniq/Lesson/:lessonId
    - GET /api/objects/learniq/Course/:courseId
    - GET /api/objects/learniq/Material|Assessment|Assignment|LtiToolPlacement
      (scoped pickers)
    - POST /api/objects/learniq/Material (new media block upload)
    - PATCH /api/objects/learniq/Lesson/:lessonId (persists the blocks array)

  PATCH, not PUT: the body carries only `blocks`, and OR's PUT is a full
  replace that would drop every other field and then fail `required`.
  The pickers address `lti-tool-placement` by its kebab-case SLUG — OR
  slugifies the identifier and does not convert PascalCase.

  No new PHP controller — every write is a call against OpenRegister's
  existing object-create/update endpoints (ADR-022). A media block never
  duplicates the referenced Material's fileRef/kind/license (design.md D3) —
  it stores only materialId.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring CourseBuilder.vue's shape.

  lesson-ai-assist-actions: when hermiq is enabled, LessonAssistPanel adds
  AI help (outline, questions, goal suggestions) and each rich text block
  gets a "rewrite in simpler words" action. Every answer lands here as an AI
  draft block with Keep and Discard; save() refuses while a draft is
  pending, and the draft marker never reaches OpenRegister
  (serialiseLessonBlocks). An added goal suggestion is saved into
  `competencyIds` with the next save. Goal titles for the panel come from
  the Course's and the Lesson's Competency rows.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
  @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
  @spec openspec/specs/course-management/spec.md#scenario-a-media-block-references-an-existing-material-rather-than-duplicating-file-metadata
  @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
-->

<template>
	<div class="lesson-composer lq-board-page">
		<div v-if="loading" class="lesson-composer__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading lesson…') }}</span>
		</div>

		<div v-else-if="error" class="lesson-composer__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<p class="lesson-composer__sr-live" aria-live="polite" role="status">
				{{ liveMessage }}
			</p>

			<header class="lesson-composer__header">
				<CnPageHeader :title="t('learniq', 'Compose lesson: {name}', { name: lesson.name || '' })" />
				<div class="lesson-composer__header-actions">
					<button class="button-vue" @click="goBack">
						{{ t('learniq', 'Back to builder') }}
					</button>
					<button class="button-vue" @click="openPlayer">
						{{ t('learniq', 'Preview') }}
					</button>
					<button
						class="button-vue button-vue--primary"
						:disabled="saving"
						@click="save">
						<span
							v-if="saving"
							class="icon-loading"
							aria-hidden="true" />
						{{ t('learniq', 'Save lesson') }}
					</button>
				</div>
			</header>
			<p
				v-if="lesson.contentType !== 'text'"
				class="lesson-composer__notice"
				role="status">
				{{
					t(
						'learniq',
						"This lesson's contentType is '{type}', not 'text' — blocks only render in LessonPlayer once contentType is set to 'text'.",
						{ type: lesson.contentType },
					)
				}}
			</p>
			<p v-if="saveError" role="alert" class="lesson-composer__inline-error">
				{{ saveError }}
			</p>
			<p v-if="saveDone" role="status" class="lesson-composer__inline-success">
				{{ t('learniq', 'Lesson saved.') }}
			</p>

			<CnDetailCard
				:title="t('learniq', 'Lesson blocks')"
				class="lesson-composer__blocks">
				<CnBlockListEditor
					v-model="blocks"
					:types="blockTypes"
					:labels="blockLabels"
					idKey="blockId"
					palette="select">
					<template #block-richText="{ block, position, update }">
						<NcButton
							v-if="assistAvailable && !block.draft"
							variant="tertiary"
							:disabled="!(block.text || '').trim()"
							:aria-label="
								t('learniq', 'Rewrite {type} block {position} in simpler words', {
									type: blockTypeLabel(block.type),
									position,
								})
							"
							@click="simplifyBlock(block)">
							<template #icon>
								<AutoFix :size="18" />
							</template>
							{{ t('learniq', 'Rewrite in simpler words') }}
						</NcButton>
						<CnMarkdownEditor
							:value="block.text || ''"
							:aria-label="t('learniq', 'Rich text content')"
							:rows="6"
							@input="(v) => update({ text: v })" />
					</template>

					<!-- teacherNote (office-file-lesson-onboarding) -->
					<template #block-teacherNote="{ block, position, update }">
						<p class="lesson-composer__teacher-note-label">
							{{
								t(
									'learniq',
									'Only staff can read this note. Learners and parents never see it.',
								)
							}}
						</p>
						<CnMarkdownEditor
							:value="block.text || ''"
							:aria-label="t('learniq', 'Teacher note in block {position}', { position })"
							:rows="4"
							@input="(v) => update({ text: v })" />
					</template>

					<template #block-media="{ block, update }">
						<NcSelect
							:modelValue="block.materialId"
							:options="materialOptions"
							:reduce="(opt) => opt.id"
							:inputLabel="t('learniq', 'Material')"
							@update:modelValue="(v) => update({ materialId: v })" />
						<NcButton :disabled="pickingFile" @click="pickAndCreateMaterial(update)">
							{{ t('learniq', 'Upload a new file…') }}
						</NcButton>
						<NcButton v-if="openMaterialEnabled" variant="secondary" data-testid="open-material-open" @click="openMaterialSearch(update)">
							{{ t('learniq', 'Search open learning material') }}
						</NcButton>
						<MaterialAttribution v-if="materialById(block.materialId)" :material="materialById(block.materialId)" />
					</template>

					<template #block-quiz="{ block, update }">
						<NcSelect
							:modelValue="block.assessmentId"
							:options="assessmentOptions"
							:reduce="(opt) => opt.id"
							:inputLabel="t('learniq', 'Assessment')"
							@update:modelValue="(v) => update({ assessmentId: v })" />
					</template>

					<template #block-assignment="{ block, update }">
						<NcSelect
							:modelValue="block.assignmentId"
							:options="assignmentOptions"
							:reduce="(opt) => opt.id"
							:inputLabel="t('learniq', 'Assignment')"
							@update:modelValue="(v) => update({ assignmentId: v })" />
					</template>

					<template #block-ltiTool="{ block, update }">
						<NcSelect
							:modelValue="block.ltiToolPlacementId"
							:options="ltiToolPlacementOptions"
							:reduce="(opt) => opt.id"
							:inputLabel="t('learniq', 'LTI tool placement')"
							@update:modelValue="(v) => update({ ltiToolPlacementId: v })" />
					</template>
				</CnBlockListEditor>
			</CnDetailCard>

			<LessonNextStepEditor
				v-if="lesson"
				:rules="nextStepRules"
				:defaultNextLessonId="defaultNextLessonId"
				:lessons="courseLessons"
				:assessments="assessments"
				@update:rules="onNextStepRules"
				@update:defaultNextLessonId="onDefaultNextLesson" />

			<LessonAssistPanel
				v-if="assistEnabled"
				ref="assistPanel"
				:blocks="blocks"
				:goals="assistGoals"
				:linkedGoalIds="competencyIds"
				:language="courseLanguage"
				@draft="onAssistDraft"
				@addGoal="onAssistAddGoal"
				@off="assistOff = true" />
		</template>
		<OpenMaterialSearchModal
v-if="openMaterialUpdate"
			:target="{ lessonId }"
			:targetLabel="lesson ? lesson.title : ''"
			@added="onOpenMaterialAdded"
			@ownFiles="onOpenMaterialOwnFiles"
			@close="openMaterialUpdate = null" />
	</div>
</template>

<script>
import { CnBlockListEditor, CnDetailCard, CnMarkdownEditor, CnPageHeader } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import LessonAssistPanel from '../components/lesson/LessonAssistPanel.vue'
import LessonNextStepEditor from '../components/lesson/LessonNextStepEditor.vue'
import MaterialAttribution from '../components/openMaterial/MaterialAttribution.vue'
import OpenMaterialSearchModal from '../modals/OpenMaterialSearchModal.vue'
import { loadPanel } from '../services/openMaterial.js'
import { GOAL_COUNT_MAX, isHermiqEnabled } from '../utils/lessonAssist.js'
import {
	BLOCK_TYPES,
	countPendingDrafts,
	diffTeacherNotes,
	emptyBlock,
	makeDraftBlock,
	mergeTeacherNotes,
	newBlockId,
	serialiseLessonBlocks,
	splitTeacherNotes,
	TEACHER_NOTE_SCHEMA,
} from '../utils/lessonBlocks.js'
import { isNextStepRefusal } from '../utils/lessonPreview.js'
import { lookupList } from '../utils/portfolioEvidence.js'
import {
	createdObject,
	readRequiredObject,
	sendJson,
} from '../utils/registerRequests.js'

/**
 * Material.kind inferred from a picked file's MIME type (design.md D3 task 4.4).
 *
 * @param {string|null|undefined} mime The picked file's MIME type.
 * @return {string} A Material.kind enum value.
 */
function inferMaterialKind(mime) {
	if (!mime) return 'other'
	if (mime.startsWith('video/')) return 'video'
	if (mime === 'application/pdf' || mime.startsWith('text/')) return 'reading'
	if (mime === 'application/zip' || mime === 'application/x-zip-compressed')
		return 'scorm'
	if (mime.startsWith('image/') || mime.includes('presentation')) return 'slides'
	return 'document'
}

export default {
	name: 'LessonComposer',

	components: {
		AutoFix,
		CnBlockListEditor,
		CnDetailCard,
		CnMarkdownEditor,
		CnPageHeader,
		LessonAssistPanel,
		LessonNextStepEditor,
		MaterialAttribution,
		NcButton,
		NcSelect,
		OpenMaterialSearchModal,
	},

	props: {
		/** Course UUID injected by vue-router from the :courseId route param. */
		courseId: {
			type: String,
			required: true,
		},

		/** Lesson UUID injected by vue-router from the :lessonId route param. */
		lessonId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			lesson: null,
			/** @type {Array<object>} Local mirror of lesson.blocks, mutated in place until Save. */
			blocks: [],
			/**
			 * The lesson's teacher notes as last loaded or saved. Notes live in
			 * the staff-only `lesson-teacher-note` schema, never in the lesson
			 * (teacher-notes-protection); save() diffs against this list.
			 *
			 * @type {Array<object>}
			 */
			loadedNotes: [],
			saving: false,
			saveError: '',
			saveDone: false,
			pickingFile: false,
			/** Whether the administrator left open learning material on. */
			openMaterialEnabled: false,
			/** The media block's update while the open material search is open. */
			openMaterialUpdate: null,
			liveMessage: '',
			/** @type {Array<object>} */
			materials: [],
			/** @type {Array<object>} */
			assessments: [],
			/** @type {Array<object>} */
			assignments: [],
			/** @type {Array<object>} */
			ltiToolPlacements: [],
			/** Hermiq is enabled, so the AI help renders (lesson-ai-assist-actions). */
			assistEnabled: isHermiqEnabled(window.OC?.appswebroots),
			/** Hermiq said the AI help is switched off for this session. */
			assistOff: false,
			/** @type {Array<{id: string, title: string}>} Course and lesson goals, in a fixed order. */
			assistGoals: [],
			/** Course language for the AI help, from Course.language. */
			courseLanguage: 'nl',
			/** @type {string[]} Local mirror of lesson.competencyIds, saved with the blocks when changed. */
			competencyIds: [],
			/** A goal suggestion was added since the last save. */
			competencyIdsDirty: false,
			// content-adaptive-next-step-and-preview: the next step rules and
			// default as edited, saved with the blocks when changed.
			nextStepRules: [],
			defaultNextLessonId: null,
			nextStepDirty: false,
			/** The course's other lessons, the targets a rule can pick. */
			courseLessons: [],
		}
	},

	computed: {
		/**
		 * The block types the palette offers, each creating its empty payload.
		 *
		 * @return {Array<{type: string, label: string, create: function(): object}>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		blockTypes() {
			return BLOCK_TYPES.map((type) => ({
				type,
				label: this.blockTypeLabel(type),
				create: () => emptyBlock(type),
			}))
		},

		/**
		 * CnBlockListEditor's texts in learniq's words.
		 *
		 * @return {object}
		 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		blockLabels() {
			return {
				add: this.t('learniq', 'Add block'),
				type: this.t('learniq', 'Block type'),
				list: this.t('learniq', 'Lesson blocks'),
				empty: this.t('learniq', 'This lesson has no blocks yet.'),
				moveUp: this.t('learniq', 'Up'),
				moveDown: this.t('learniq', 'Down'),
				remove: this.t('learniq', 'Remove'),
				draft: this.t('learniq', 'AI draft'),
				keep: this.t('learniq', 'Keep'),
				discard: this.t('learniq', 'Discard'),
				kept: this.t('learniq', 'AI draft kept.'),
				discarded: this.t('learniq', 'AI draft discarded.'),
				moved: this.t('learniq', 'Block moved to position {position} of {total}'),
			}
		},

		/**
		 * Whether the per-block AI rewrite action shows: hermiq is enabled and
		 * has not said the feature is switched off.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer
		 */
		assistAvailable() {
			return this.assistEnabled && !this.assistOff
		},

		/**
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		materialOptions() {
			return this.materials.map((m) => ({ id: m.id, label: m.title }))
		},

		/**
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		assessmentOptions() {
			return this.assessments.map((a) => ({ id: a.id, label: a.title }))
		},

		assignmentOptions() {
			return this.assignments.map((a) => ({ id: a.id, label: a.title }))
		},

		ltiToolPlacementOptions() {
			return this.ltiToolPlacements.map((p) => ({
				id: p.id,
				label: p.title || p.name || p.id,
			}))
		},
	},

	/**
	 * Load the lesson, then whether open learning material is on.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
	 */
	async mounted() {
		await this.load()
		await this.loadOpenMaterialPanel()
	},

	methods: {
		/**
		 * Whether to offer the open material search: the administrator's switch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
		 */
		async loadOpenMaterialPanel() {
			try {
				const panel = await loadPanel(axios, generateUrl)
				this.openMaterialEnabled = panel.enabled === true
			} catch {
				this.openMaterialEnabled = false
			}
		},

		/**
		 * Open the search panel for this media block.
		 *
		 * @param {function(object): void} update CnBlockListEditor's update for the media block.
		 * @return {void}
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
		 */
		openMaterialSearch(update) {
			this.openMaterialUpdate = update
		},

		/**
		 * Put the added Material in the block that opened the panel, by UUID only.
		 *
		 * @param {object} material The new Material.
		 * @return {void}
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		onOpenMaterialAdded(material) {
			this.materials.push(material)
			this.openMaterialUpdate({ materialId: material.id })
			this.openMaterialUpdate = null
		},

		/**
		 * Close the search and open the file picker for the same block instead.
		 *
		 * @return {void}
		 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-does-not-answer
		 */
		onOpenMaterialOwnFiles() {
			const update = this.openMaterialUpdate
			this.openMaterialUpdate = null
			this.pickAndCreateMaterial(update)
		},

		/**
		 * @param {string} id A Material UUID.
		 * @return {object|null} The loaded Material.
		 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
		 */
		materialById(id) {
			return this.materials.find((m) => m.id === id) || null
		},

		/**
		 * Load the Lesson and every picker source scoped to its Course:
		 * existing Materials attached to this Lesson (media block picker),
		 * and Assessments/Assignments/LtiToolPlacements scoped to courseId
		 * (quiz/assignment/ltiTool block pickers).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.lesson = await this.fetchObject('Lesson', this.lessonId)
				// Teacher notes come from their own staff-only store and are
				// shown in place among the blocks (teacher-notes-protection).
				this.loadedNotes = await this.fetchList(
					TEACHER_NOTE_SCHEMA,
					`lessonId=${this.lessonId}&_limit=500`,
				)
				this.blocks = mergeTeacherNotes(
					this.lesson.blocks || [],
					this.loadedNotes,
				)
				this.competencyIds = (this.lesson.competencyIds || []).slice()
				this.competencyIdsDirty = false
				if (this.assistEnabled) {
					await this.loadAssistGoals()
				}

				this.nextStepRules = Array.isArray(this.lesson.nextStepRules)
					? this.lesson.nextStepRules
					: []
				this.defaultNextLessonId = this.lesson.defaultNextLessonId ?? null
				this.nextStepDirty = false
				const [
					materials,
					assessments,
					assignments,
					ltiToolPlacements,
					courseLessons,
				] = await Promise.all([
					this.fetchList(
						'Material',
						`lessonId=${this.lessonId}&_limit=200`,
					),
					this.fetchList(
						'exam',
						`courseId=${this.courseId}&_limit=200`,
					),
					this.fetchList(
						'Assignment',
						`courseId=${this.courseId}&_limit=200`,
					),
					this.fetchList(
						'lti-tool-placement',
						`courseId=${this.courseId}&_limit=200`,
					),
					this.fetchList(
						'Lesson',
						`courseId=${this.courseId}&_limit=500`,
					),
				])
				this.materials = materials
				this.assessments = assessments
				this.assignments = assignments
				this.ltiToolPlacements = ltiToolPlacements
				this.courseLessons = courseLessons.filter(
					(l) => l.id !== this.lessonId,
				)
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to load the lesson. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] load error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the goals the AI help offers: the Course's and the Lesson's
		 * `competencyIds`, course first, capped at hermiq's 100-title limit,
		 * resolved to `{id, title}` from their Competency rows. Also reads the
		 * course language. A goal or course that fails to load is left out;
		 * the AI help still works with the goals that did load.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only
		 */
		async loadAssistGoals() {
			const course = await this.fetchObject('Course', this.courseId).catch(
				() => ({}),
			)
			if (typeof course.language === 'string' && course.language !== '') {
				this.courseLanguage = course.language
			}

			const ids = [
				...new Set([
					...(course.competencyIds || []),
					...(this.lesson.competencyIds || []),
				]),
			].slice(0, GOAL_COUNT_MAX)
			const rows = await Promise.all(
				ids.map((id) =>
					this.fetchObject('Competency', id).catch(() => null),
				),
			)
			this.assistGoals = rows
				.map((row, i) => ({
					id: ids[i],
					title: row ? String(row.title || row.code || '') : '',
				}))
				.filter((goal) => goal.title !== '')
		},

		/**
		 * Rewrite one rich text block in simpler words through the AI help.
		 *
		 * @param {object} block A richText block.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 */
		async simplifyBlock(block) {
			if (this.$refs.assistPanel) {
				await this.$refs.assistPanel.simplifyBlock(block)
			}
		},

		/**
		 * Insert an AI answer as a draft block: after its source block for a
		 * rewrite, otherwise at the end.
		 *
		 * @param {{text: string, action: string, provider: string|null, afterBlockId: string|null}} draft The draft.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
		 */
		onAssistDraft(draft) {
			const block = makeDraftBlock({
				blockId: newBlockId(),
				text: draft.text,
				action: draft.action,
				provider: draft.provider,
				note: this.t(
					'learniq',
					'Check and edit it before you keep it. Model service: {provider}',
					{ provider: draft.provider || this.t('learniq', 'unknown') },
				),
			})
			const after = this.blocks.findIndex(
				(b) => b.blockId === draft.afterBlockId,
			)
			if (after >= 0) {
				this.blocks.splice(after + 1, 0, block)
			} else {
				this.blocks.push(block)
			}
			this.saveDone = false
			this.liveMessage = this.t(
				'learniq',
				'AI draft added as block {position}. Keep or discard it.',
				{ position: this.blocks.indexOf(block) + 1 },
			)
		},



		/**
		 * Add a goal the AI help suggested to the lesson; saved with the next save.
		 *
		 * @param {string} goalId The Competency id.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#scenario-a-teacher-adds-a-suggested-goal
		 */
		onAssistAddGoal(goalId) {
			if (this.competencyIds.includes(goalId)) return
			this.competencyIds.push(goalId)
			this.competencyIdsDirty = true
			this.saveDone = false
			this.liveMessage = this.t(
				'learniq',
				'Goal added. Save the lesson to keep it.',
			)
		},

		/** @return {void} */
		/**
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		goBack() {
			if (this.$router) {
				this.$router
					.push({
						name: 'CourseBuilder',
						params: { courseId: this.courseId },
					})
					.catch(() => {})
			}
		},

		/** @return {void} */
		openPlayer() {
			if (this.$router) {
				this.$router
					.push({
						name: 'LessonPlayer',
						params: { courseId: this.courseId, lessonId: this.lessonId },
					})
					.catch(() => {})
			}
		},

		/**
		 * Fetch a single OR object.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `lesson`,
		 *   `lti-tool-placement`. Lookup is case-insensitive but does NOT
		 *   convert PascalCase to kebab-case, so a multi-word key like
		 *   `LtiToolPlacement` matches no schema and the endpoint 404s —
		 *   which fetchList() then reports as an empty list.
		 * @param {string} objId Object UUID.
		 * @return {Promise<object>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async fetchObject(schema, objId) {
			return readRequiredObject(
				generateUrl(`/apps/openregister/api/objects/learniq/${schema}/${objId}`),
				`${schema} fetch failed`,
			)
		},

		/**
		 * Fetch a filtered list of OR objects.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `lesson`,
		 *   `lti-tool-placement`. Lookup is case-insensitive but does NOT
		 *   convert PascalCase to kebab-case, so a multi-word key like
		 *   `LtiToolPlacement` matches no schema and the endpoint 404s —
		 *   which fetchList() then reports as an empty list.
		 * @param {string} query Pre-built query string.
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async fetchList(schema, query) {
			return lookupList(
				generateUrl(`/apps/openregister/api/objects/learniq/${schema}?${query}`),
			)
		},

		/**
		 * Create an OR object.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `lesson`,
		 *   `lti-tool-placement`. Lookup is case-insensitive but does NOT
		 *   convert PascalCase to kebab-case, so a multi-word key like
		 *   `LtiToolPlacement` matches no schema and the endpoint 404s —
		 *   which fetchList() then reports as an empty list.
		 * @param {object} body Payload.
		 * @return {Promise<object>} The created object.
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		async createObject(schema, body) {
			return createdObject(
				generateUrl(`/apps/openregister/api/objects/learniq/${schema}`),
				{ body, failure: `${schema} create failed` },
			)
		},


		/**
		 * Human-readable label for a block type.
		 *
		 * @param {string} type Block type value.
		 * @return {string}
		 * @spec exclude Presentation-only label map from the fixed block-type values to their localized display names; no behavioural spec requirement of its own.
		 */
		blockTypeLabel(type) {
			const labels = {
				richText: this.t('learniq', 'Rich text'),
				media: this.t('learniq', 'Media'),
				quiz: this.t('learniq', 'Quiz'),
				assignment: this.t('learniq', 'Assignment'),
				ltiTool: this.t('learniq', 'External tool'),
				teacherNote: this.t('learniq', 'Teacher note'),
			}
			return labels[type] ?? type
		},





		/**
		 * The `blocks` array as the Lesson schema will accept it: the required
		 * trio plus only the payload its type populates. The rules (and why an
		 * explicit null payload is rejected) live in serialiseLessonBlocks();
		 * it also drops the local AI draft marker.
		 *
		 * @return {Array<object>} Blocks safe to persist.
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 */
		serialisableBlocks() {
			// Notes stay out of the lesson; the lesson blocks are renumbered
			// without them so their order stays contiguous.
			const { blocks } = splitTeacherNotes(this.blocks)
			return serialiseLessonBlocks(
				blocks.map((block, index) => ({ ...block, order: index + 1 })),
			)
		},

		/**
		 * Write the teacher notes to their staff-only store: create new ones,
		 * update changed ones, delete removed ones, matched by `blockId`.
		 * Runs after the lesson itself was saved.
		 *
		 * @return {Promise<boolean>} True when every note write succeeded.
		 * @spec openspec/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store
		 */
		async saveTeacherNotes() {
			const { notes } = splitTeacherNotes(this.blocks)
			const diff = diffTeacherNotes(this.loadedNotes, notes)
			const noteUrl = (id) =>
				generateUrl(
					`/apps/openregister/api/objects/learniq/${TEACHER_NOTE_SCHEMA}/${id}`,
				)
			const headers = {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			}
			const saved = new Map(this.loadedNotes.map((n) => [n.blockId, n]))
			let ok = true
			for (const note of diff.create) {
				try {
					const created = await this.createObject(TEACHER_NOTE_SCHEMA, {
						...note,
						lessonId: this.lessonId,
						tenant_id: this.lesson?.tenant_id,
					})
					saved.set(note.blockId, {
						...note,
						id: created?.id ?? created?.uuid,
					})
				} catch {
					ok = false
				}
			}
			for (const note of diff.update) {
				const { id, ...fields } = note
				const resp = await fetch(noteUrl(id), {
					method: 'PATCH',
					headers,
					body: JSON.stringify(fields),
				}).catch(() => null)
				if (resp?.ok) {
					saved.set(note.blockId, { ...fields, id })
				} else {
					ok = false
				}
			}
			for (const id of diff.remove) {
				const resp = await fetch(noteUrl(id), {
					method: 'DELETE',
					headers,
				}).catch(() => null)
				if (resp?.ok) {
					for (const [blockId, stored] of saved) {
						if ((stored.id ?? stored.uuid) === id) saved.delete(blockId)
					}
				} else {
					ok = false
				}
			}
			this.loadedNotes = [...saved.values()]
			return ok
		},

		/**
		 * The PATCH body: the blocks, plus `competencyIds` only when a goal
		 * suggestion was added, so a plain save never rewrites the goals.
		 *
		 * @return {object} The body.
		 * @spec openspec/specs/course-management/spec.md#scenario-a-teacher-adds-a-suggested-goal
		 */
		saveBody() {
			const body = { blocks: this.serialisableBlocks() }
			if (this.competencyIdsDirty) {
				body.competencyIds = this.competencyIds.slice()
			}
			if (this.nextStepDirty) {
				body.nextStepRules = this.nextStepRules
				body.defaultNextLessonId = this.defaultNextLessonId
			}
			return body
		},

		/**
		 * The next step editor changed its rules.
		 *
		 * @param {Array<object>} rules The rules as they will be saved.
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		onNextStepRules(rules) {
			this.nextStepRules = rules
			this.nextStepDirty = true
			this.saveDone = false
		},

		/**
		 * The next step editor changed the default next lesson.
		 *
		 * @param {string|null} lessonId The lesson, or null for none.
		 * @return {void}
		 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
		 */
		onDefaultNextLesson(lessonId) {
			this.defaultNextLessonId = lessonId
			this.nextStepDirty = true
			this.saveDone = false
		},





		/**
		 * Open the NC Files picker, then create a new Material for the
		 * picked file (fileRef = picked path, lessonId = current lesson,
		 * kind inferred from the file's MIME type) and store the resulting
		 * Material's UUID as this block's materialId. Never duplicates the
		 * Material's own fields onto the block (design.md D3).
		 *
		 * @param {function(object): void} update CnBlockListEditor's update for the media block.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#scenario-a-media-block-references-an-existing-material-rather-than-duplicating-file-metadata
		 */
		async pickAndCreateMaterial(update) {
			this.pickingFile = true
			try {
				const picker = getFilePickerBuilder(
					this.t('learniq', 'Choose a file for this block'),
				)
					.setMultiSelect(false)
					.build()
				const nodes = await picker.pickNodes()
				const node = Array.isArray(nodes) ? nodes[0] : nodes
				if (!node) return

				const created = await this.createObject('Material', {
					title: node.basename || node.path,
					kind: inferMaterialKind(node.mime),
					fileRef: node.path,
					lessonId: this.lessonId,
					tenant_id: this.lesson.tenant_id,
				})
				this.materials.push(created)
				update({ materialId: created.id })
			} catch (err) {
				// FilePickerClosed on cancel — not an error the user needs to see.
				if (
					err
					&& err.constructor
					&& err.constructor.name === 'FilePickerClosed'
				)
					return
				this.error = this.t(
					'learniq',
					'Failed to attach the picked file. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] pickAndCreateMaterial error', err)
			} finally {
				this.pickingFile = false
			}
		},

		/**
		 * Persist the full blocks array on the Lesson via OR's existing
		 * object-update endpoint, plus `competencyIds` when a suggested goal
		 * was added. Refused while an AI draft is pending: a draft becomes
		 * lesson text only after the teacher keeps it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
		 * @spec openspec/specs/course-management/spec.md#scenario-a-pending-draft-blocks-the-save
		 */
		async save() {
			this.saveError = ''
			this.saveDone = false
			if (countPendingDrafts(this.blocks) > 0) {
				this.saveError = this.t(
					'learniq',
					'Keep or discard the AI drafts before you save the lesson.',
				)
				return
			}
			this.saving = true

			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/learniq/Lesson/${this.lessonId}`,
				)
				// ⚠️ PATCH, not PUT — this body carries only `blocks` (plus
				// `competencyIds` after an added goal suggestion), and OR
				// routes PUT (`objects#update`) as a full REPLACE. A partial PUT
				// drops every omitted field and then fails the schema's
				// `required` list (`Lesson.required` is
				// `[courseId, name, order, contentType, tenant_id]`), answering
				// 400. PATCH (`objects#patch`) is the read-merge-write partial
				// update. Same fix as CourseBuilder.updateObject().
				const resp = await sendJson(url, {
					method: 'PATCH',
					body: this.saveBody(),
				})
				if (!resp.ok) {
					const refused = await resp.json().catch(() => ({}))
					if (isNextStepRefusal(refused)) {
						// The server's reason, in the author's language
						// (LessonNextStepGuard).
						this.saveError = this.t(
							'learniq',
							'A next step can only go to a lesson of the same course.',
						)
						return
					}
					throw new Error(`Lesson blocks save failed: ${resp.status}`)
				}
				this.nextStepDirty = false
				this.lesson.blocks = splitTeacherNotes(this.blocks).blocks
				if (!(await this.saveTeacherNotes())) {
					this.saveError = this.t(
						'learniq',
						'The lesson was saved, but a teacher note was not. Save again to retry.',
					)
				}
				if (this.competencyIdsDirty) {
					this.lesson.competencyIds = this.competencyIds.slice()
					this.competencyIdsDirty = false
				}
				this.saveDone = this.saveError === ''
			} catch (err) {
				this.saveError = this.t(
					'learniq',
					'Failed to save the lesson. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[LessonComposer] save error', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.lesson-composer__loading,
.lesson-composer__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.lesson-composer__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.lesson-composer__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
	margin-bottom: 8px;
}

.lesson-composer__header-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.lesson-composer__notice {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.lesson-composer__inline-error {
	color: var(--color-error);
	margin-bottom: 8px;
}

.lesson-composer__inline-success {
	color: var(--color-success);
	margin-bottom: 8px;
}

.lesson-composer__teacher-note-label {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
