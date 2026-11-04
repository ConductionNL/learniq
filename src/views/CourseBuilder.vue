<!--
  CourseBuilder.vue
  Custom page component for the CourseBuilder manifest page (type: custom).

  course-authoring-ux: the Course/Module/Lesson tree editor. A module is a
  child Course (parentCourseId = this course); lessons live under each
  module. Supports create/delete of modules and lessons, dual-path reorder
  (drag-and-drop via vuedraggable AND keyboard-operable Move up/down
  controls — WCAG 2.1 AA SC 2.1.1, design.md D4), "Save as template" (writes
  a CourseTemplate skeleton from the current tree) and "New course from
  template" (instantiates a CourseTemplate into a fresh, independent Course
  tree — the "Clone for next year" capability).

  Reads and template actions go through learniq's CourseTemplateController,
  which runs every read and write with the user's own OpenRegister permissions:
    - GET  /apps/learniq/api/courses/:courseId/structure (course, modules and
      all their lessons; one query for the lessons)
    - POST /apps/learniq/api/courses/:courseId/save-as-template
    - POST /apps/learniq/api/course-templates/:templateId/instantiate
  Edits to the tree go to OpenRegister's REST API:
    - POST /api/objects/learniq/Course | Lesson
    - PATCH /api/objects/learniq/Course/:id | Lesson/:id  (order updates)
    - DELETE /api/objects/learniq/Course/:id | Lesson/:id
    - GET  /api/objects/learniq/course-template

  PATCH, not PUT, for the order updates: OR's PUT is a full replace and a
  partial body fails the schema's `required` list. See updateObject().
  Multi-word schemas are addressed by their kebab-case SLUG
  (`course-template`, `curriculum-plan`) — OR slugifies the identifier and
  does not convert PascalCase, so `CourseTemplate` 404s.

  Instantiating a template is one request: the server creates the whole tree
  and removes what it created when a write fails, so a half-built course
  cannot be left behind.

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring PortfolioBuilder.vue's existing shape.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/specs/course-management/spec.md#requirement-a-course-declares-its-display-order-among-sibling-modules
  @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
  @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
  @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
-->

<template>
	<div class="course-builder lq-board-page">
		<div v-if="loading" class="course-builder__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading course…') }}</span>
		</div>

		<div v-else-if="error" class="course-builder__error" role="alert">
			<span class="icon-error" aria-hidden="true" />
			<p>{{ error }}</p>
		</div>

		<template v-else>
			<!-- Move/status announcements for assistive technology (design.md D4). -->
			<p class="course-builder__sr-live" aria-live="polite" role="status">
				{{ liveMessage }}
			</p>

			<header class="course-builder__header">
				<CnPageHeader :title="t('learniq', 'Course builder: {name}', { name: course.name || '' })" />
				<div class="course-builder__header-actions">
					<button class="button-vue" @click="goBack">
						{{ t('learniq', 'Back to course') }}
					</button>
					<button
						class="button-vue"
						@click="showSaveTemplate = !showSaveTemplate">
						{{ t('learniq', 'Save as template') }}
					</button>
					<button class="button-vue" @click="onOpenInstantiate">
						{{ t('learniq', 'New course from template') }}
					</button>
				</div>
			</header>

			<!-- Save as template -->
			<CnDetailCard
				v-if="showSaveTemplate"
				:title="t('learniq', 'Save this course as a template')"
				class="course-builder__panel">
				<label class="course-builder__field-label" for="cb-template-name">
					{{ t('learniq', 'Template name') }}
				</label>
				<input
					id="cb-template-name"
					v-model="saveTemplateForm.name"
					type="text"
					class="course-builder__input" />
				<label class="course-builder__field-label" for="cb-template-desc">
					{{ t('learniq', 'Description') }}
				</label>
				<textarea
					id="cb-template-desc"
					v-model="saveTemplateForm.description"
					class="course-builder__textarea"
					rows="2" />
				<div class="course-builder__panel-actions">
					<button
						class="button-vue button-vue--primary"
						:disabled="savingTemplate || !saveTemplateForm.name"
						@click="saveAsTemplate">
						<span
							v-if="savingTemplate"
							class="icon-loading"
							aria-hidden="true" />
						{{ t('learniq', 'Save template') }}
					</button>
				</div>
				<p
					v-if="saveTemplateError"
					role="alert"
					class="course-builder__inline-error">
					{{ saveTemplateError }}
				</p>
				<p
					v-if="saveTemplateDone"
					role="status"
					class="course-builder__inline-success">
					{{ t('learniq', 'Template saved.') }}
				</p>
			</CnDetailCard>

			<!-- New course from template -->
			<CnDetailCard
				v-if="showInstantiate"
				:title="t('learniq', 'Create a new course from a template')"
				class="course-builder__panel">
				<NcSelect
					v-model="instantiateForm.templateId"
					:options="templateOptions"
					:reduce="(opt) => opt.id"
					:inputLabel="t('learniq', 'Template')"
					:aria-label-combobox="t('learniq', 'Template')" />
				<label class="course-builder__field-label" for="cb-new-course-name">
					{{ t('learniq', 'New course name') }}
				</label>
				<input
					id="cb-new-course-name"
					v-model="instantiateForm.name"
					type="text"
					class="course-builder__input" />
				<div class="course-builder__panel-actions">
					<button
						class="button-vue button-vue--primary"
						:disabled="
							instantiating
							|| !instantiateForm.templateId
							|| !instantiateForm.name
						"
						@click="instantiateTemplate">
						<span
							v-if="instantiating"
							class="icon-loading"
							aria-hidden="true" />
						{{ t('learniq', 'Create course') }}
					</button>
				</div>
				<p
					v-if="instantiateError"
					role="alert"
					class="course-builder__inline-error">
					{{ instantiateError }}
				</p>
			</CnDetailCard>

			<!-- Modules -->
			<CnDetailCard
				:title="t('learniq', 'Modules')"
				class="course-builder__modules">

				<p class="course-builder__reorder-hint">
					{{
						t(
							'learniq',
							'Drag a chapter or lesson to another place, or use the Move up and Move down buttons. With the keyboard: Tab to the button, Enter moves it. The new order is saved at once and read out.',
						)
					}}
				</p>
				<!-- vuedraggable 4 (Vue 3) renders its rows through the REQUIRED
				     `#item` scoped slot and computes each key from `itemKey`.
				     A `v-for` in the default slot is the Vue 2 API: v4 throws
				     "draggable element must have an item slot" from
				     computeNodes(), which kills the whole Modules section's
				     render — the list never appears and neither does any module
				     added through the form below it. -->
				<Draggable
					v-model="modules"
					tag="ul"
					class="course-builder__module-list"
					handle=".course-builder__handle"
					itemKey="id"
					@end="onModulesDragEnd">
					<template #item="{ element: module, index: mIdx }">
						<li class="course-builder__module">
							<div class="course-builder__module-row">
								<span
									class="course-builder__handle icon-menu"
									aria-hidden="true" />
								<span class="course-builder__module-name">{{
									module.name
								}}</span>
								<button
									type="button"
									class="course-builder__icon-btn"
									:disabled="mIdx === 0"
									:aria-label="
										t('learniq', 'Move module \'{name}\' up', {
											name: module.name,
										})
									"
									@click="moveModuleUp(mIdx)">
									<ChevronUp :size="18" />
									<span class="course-builder__move-label">{{ t('learniq', 'Move up') }}</span>
								</button>
								<button
									type="button"
									class="course-builder__icon-btn"
									:disabled="mIdx === modules.length - 1"
									:aria-label="
										t('learniq', 'Move module \'{name}\' down', {
											name: module.name,
										})
									"
									@click="moveModuleDown(mIdx)">
									<ChevronDown :size="18" />
									<span class="course-builder__move-label">{{ t('learniq', 'Move down') }}</span>
								</button>
								<button
									type="button"
									class="course-builder__icon-btn"
									:aria-label="
										t('learniq', 'Delete module \'{name}\'', {
											name: module.name,
										})
									"
									@click="deleteModule(module, mIdx)">
									<DeleteOutline :size="18" />
								</button>
							</div>

							<!-- Same v4 `#item` contract as the modules list above. -->
							<Draggable
								v-model="module.lessons"
								tag="ul"
								class="course-builder__lesson-list"
								handle=".course-builder__handle"
								itemKey="id"
								@end="onLessonsDragEnd(module, $event)">
								<template #item="{ element: lesson, index: lIdx }">
									<li class="course-builder__lesson">
										<span
											class="course-builder__handle icon-menu"
											aria-hidden="true" />
										<span class="course-builder__lesson-name">{{
											lesson.name
										}}</span>
										<span class="course-builder__lesson-type">{{
											lesson.contentType
										}}</span>
										<button
											type="button"
											class="course-builder__icon-btn"
											:disabled="lIdx === 0"
											:aria-label="
												t(
													'learniq',
													'Move lesson \'{name}\' up',
													{
														name: lesson.name,
													},
												)
											"
											@click="moveLessonUp(module, lIdx)">
											<ChevronUp :size="16" />
											<span class="course-builder__move-label">{{ t('learniq', 'Move up') }}</span>
										</button>
										<button
											type="button"
											class="course-builder__icon-btn"
											:disabled="
												lIdx === module.lessons.length - 1
											"
											:aria-label="
												t(
													'learniq',
													'Move lesson \'{name}\' down',
													{
														name: lesson.name,
													},
												)
											"
											@click="moveLessonDown(module, lIdx)">
											<ChevronDown :size="16" />
											<span class="course-builder__move-label">{{ t('learniq', 'Move down') }}</span>
										</button>
										<button
											type="button"
											class="button-vue"
											@click="openComposer(lesson)">
											{{ t('learniq', 'Compose') }}
										</button>
										<button
											type="button"
											class="button-vue"
											@click="openPlayer(lesson)">
											{{ t('learniq', 'Preview') }}
										</button>
										<button
											type="button"
											class="course-builder__icon-btn"
											:aria-label="
												t(
													'learniq',
													'Delete lesson \'{name}\'',
													{
														name: lesson.name,
													},
												)
											"
											@click="deleteLesson(module, lIdx)">
											<DeleteOutline :size="16" />
										</button>
									</li>
								</template>
							</Draggable>

							<div class="course-builder__add-row">
								<input
									v-model="newLessonNames[module.id]"
									type="text"
									class="course-builder__input"
									:placeholder="t('learniq', 'New lesson name')"
									:aria-label="
										t(
											'learniq',
											'New lesson name for module {name}',
											{ name: module.name },
										)
									" />
								<button
									type="button"
									class="button-vue"
									:disabled="!newLessonNames[module.id]"
									@click="addLesson(module)">
									<PlusIcon :size="16" />
									{{ t('learniq', 'Add lesson') }}
								</button>
							</div>
						</li>
					</template>
				</Draggable>

				<div class="course-builder__add-row">
					<input
						v-model="newModuleName"
						type="text"
						class="course-builder__input"
						:placeholder="t('learniq', 'New module name')"
						:aria-label="t('learniq', 'New module name')" />
					<button
						type="button"
						class="button-vue button-vue--secondary"
						:disabled="!newModuleName"
						@click="addModule">
						<PlusIcon :size="16" />
						{{ t('learniq', 'Add module') }}
					</button>
				</div>
			</CnDetailCard>
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'CourseBuilder',

	components: {
		CnDetailCard,
		ChevronDown,
		ChevronUp,
		CnPageHeader,
		DeleteOutline,
		Draggable: draggable,
		NcSelect,
		PlusIcon,
	},

	props: {
		/** Course UUID injected by vue-router from the :courseId route param. */
		courseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			course: null,
			/** @type {Array<object>} Each entry: {...Course fields, lessons: Array<Lesson>}. */
			modules: [],
			liveMessage: '',
			newModuleName: '',
			/** @type {Record<string, string>} Pending new-lesson-name input keyed by module id. */
			newLessonNames: {},
			showSaveTemplate: false,
			savingTemplate: false,
			saveTemplateError: '',
			saveTemplateDone: false,
			saveTemplateForm: { name: '', description: '' },
			showInstantiate: false,
			instantiating: false,
			instantiateError: '',
			instantiateForm: { templateId: '', name: '' },
			/** @type {Array<object>} */
			templates: [],
		}
	},

	computed: {
		/**
		 * NcSelect options for the CourseTemplate picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		templateOptions() {
			return this.templates.map((tpl) => ({ id: tpl.id, label: tpl.name }))
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the Course, its child modules (Course rows with
		 * parentCourseId = this course), and each module's Lessons in one
		 * request. The server sorts modules and lessons by order with a
		 * missing order last, and reads all lessons in a single query.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-course-declares-its-display-order-among-sibling-modules
		 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/learniq/api/courses/{id}/structure', { id: this.courseId }),
				)
				this.course = data.course
				this.modules = (data.modules || []).map((module) => ({
					...module,
					lessons: module.lessons || [],
				}))
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to load the course. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] load error', err)
			} finally {
				this.loading = false
			}
		},

		/** @return {void} */
		/**
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		goBack() {
			if (this.$router) {
				this.$router
					.push({ name: 'CourseDetail', params: { id: this.courseId } })
					.catch(() => {})
			}
		},

		/**
		 * Navigate to LessonComposer for the given lesson.
		 *
		 * @param {object} lesson The Lesson row.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		openComposer(lesson) {
			if (this.$router) {
				this.$router
					.push({
						name: 'LessonComposer',
						params: { courseId: this.courseId, lessonId: lesson.id },
					})
					.catch(() => {})
			}
		},

		/**
		 * Navigate to LessonPlayer for the given lesson (preview).
		 *
		 * @param {object} lesson The Lesson row.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		openPlayer(lesson) {
			if (this.$router) {
				this.$router
					.push({
						name: 'LessonPlayer',
						params: { courseId: this.courseId, lessonId: lesson.id },
					})
					.catch(() => {})
			}
		},

		/**
		 * Fetch a filtered list of OR objects.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `course`, `course-template`.
		 *   Lookup is case-insensitive but does NOT convert PascalCase to
		 *   kebab-case, so a multi-word key like `CourseTemplate` lowercases to
		 *   `coursetemplate`, matches no schema, and the endpoint 404s.
		 * @param {string} query Pre-built query string.
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async fetchList(schema, query) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}?${query}`,
			)
			const resp = await fetch(url, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) return []
			const json = await resp.json()
			return json.results ?? json.objects ?? (Array.isArray(json) ? json : [])
		},

		/**
		 * Create an OR object.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `course`, `course-template`.
		 *   Lookup is case-insensitive but does NOT convert PascalCase to
		 *   kebab-case, so a multi-word key like `CourseTemplate` lowercases to
		 *   `coursetemplate`, matches no schema, and the endpoint 404s.
		 * @param {object} body Payload.
		 * @return {Promise<object>} The created object.
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async createObject(schema, body) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}`,
			)
			const resp = await fetch(url, {
				method: 'POST',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(body),
			})
			if (!resp.ok) throw new Error(`${schema} create failed: ${resp.status}`)
			const json = await resp.json()
			return json.object ?? json ?? {}
		},

		/**
		 * Partially update an OR object.
		 *
		 * ⚠️ PATCH, not PUT. OpenRegister routes `objects#update` (PUT) as a
		 * full REPLACE and `objects#patch` (PATCH) as a read-merge-write
		 * partial update (appinfo/routes.php 796-797). Sending only the changed
		 * fields over PUT therefore drops every field you omitted, and the
		 * schema's `required` list rejects the result: `Lesson.required` is
		 * `[courseId, name, order, contentType, tenant_id]`, so persistOrder's
		 * `{ order }` body answered **400** and reordering lessons could never
		 * be saved. PATCH merges into the stored object and validates the
		 * merged result, so `{ order }` is enough.
		 *
		 * OR's optimistic-concurrency guard (`_expectedUpdated`) is opt-in;
		 * omitting it keeps the plain last-write-wins merge this caller wants.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `course`, `course-template`.
		 *   Lookup is case-insensitive but does NOT convert PascalCase to
		 *   kebab-case, so a multi-word key like `CourseTemplate` lowercases to
		 *   `coursetemplate`, matches no schema, and the endpoint 404s.
		 * @param {string} objId Object UUID.
		 * @param {object} patch Partial payload.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async updateObject(schema, objId, patch) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}/${objId}`,
			)
			const resp = await fetch(url, {
				method: 'PATCH',
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(patch),
			})
			if (!resp.ok) throw new Error(`${schema} update failed: ${resp.status}`)
		},

		/**
		 * Delete an OR object.
		 *
		 * @param {string} schema OR schema SLUG, as registered in
		 *   lib/Settings/learniq_register.json — `course`, `course-template`.
		 *   Lookup is case-insensitive but does NOT convert PascalCase to
		 *   kebab-case, so a multi-word key like `CourseTemplate` lowercases to
		 *   `coursetemplate`, matches no schema, and the endpoint 404s.
		 * @param {string} objId Object UUID.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async deleteObject(schema, objId) {
			const url = generateUrl(
				`/apps/openregister/api/objects/learniq/${schema}/${objId}`,
			)
			const resp = await fetch(url, {
				method: 'DELETE',
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			if (!resp.ok) throw new Error(`${schema} delete failed: ${resp.status}`)
		},

		/**
		 * Recompute contiguous 1-based `order` for every item in `list` and
		 * persist only the ones that changed. The single place both the
		 * drag-and-drop handlers and the keyboard move handlers converge on
		 * (design.md D4) — neither path re-implements order bookkeeping.
		 *
		 * @param {Array<object>} list Modules or lessons array, already in its final order.
		 * @param {string} schema 'Course' (modules) or 'Lesson' (lessons).
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		async persistOrder(list, schema) {
			const updates = []
			list.forEach((item, idx) => {
				const newOrder = idx + 1
				if (item.order !== newOrder) {
					item.order = newOrder
					updates.push(
						this.updateObject(schema, item.id, { order: newOrder }),
					)
				}
			})
			if (updates.length) {
				await Promise.all(updates)
			}
		},

		/**
		 * Move one item of a list, persist the resulting order, and announce
		 * the result. Used by the keyboard move-up/move-down controls; the
		 * drag-and-drop `@end` handlers reuse `persistOrder` directly since
		 * vuedraggable's `v-model` has already spliced the array by the time
		 * `@end` fires.
		 *
		 * @param {{list: Array<object>, from: number, to: number, schema: string}} move
		 *   The list, the current and target index, and 'Course' or 'Lesson'.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		async reorder(move) {
			const { list, from, to } = move
			if (from === to || to < 0 || to >= list.length) {
				return
			}
			const [moved] = list.splice(from, 1)
			list.splice(to, 0, moved)
			await this.persistOrder(list, move.schema)
			this.announceMove(move)
		},

		/**
		 * Read out where an item went and that the order is saved, as the
		 * board LqCursus draws it: "Les Signaalwoorden verplaatst naar
		 * positie 2 van 5. Opgeslagen."
		 *
		 * @param {{list: Array<object>, to: number, schema: string}} move The finished move.
		 * @return {void}
		 * @spec openspec/specs/course-management/spec.md#requirement-lessons-within-a-course-and-blocks-within-a-lesson-are-reorderable-by-drag-and-drop-and-by-keyboard
		 */
		announceMove(move) {
			const values = {
				name: move.list[move.to]?.name ?? '',
				pos: move.to + 1,
				total: move.list.length,
			}
			this.liveMessage = move.schema === 'Course'
				? this.t('learniq', 'Chapter {name} moved to position {pos} of {total}. Saved.', values)
				: this.t('learniq', 'Lesson {name} moved to position {pos} of {total}. Saved.', values)
		},

		/**
		 * Move a module one position earlier.
		 *
		 * @param {number} idx Module index.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		moveModuleUp(idx) {
			return this.reorder({ list: this.modules, from: idx, to: idx - 1, schema: 'Course' })
		},

		/**
		 * Move a module one position later.
		 *
		 * @param {number} idx Module index.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		moveModuleDown(idx) {
			return this.reorder({ list: this.modules, from: idx, to: idx + 1, schema: 'Course' })
		},

		/**
		 * @param {object} module Parent module.
		 * @param {number} idx Lesson index within module.lessons.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		moveLessonUp(module, idx) {
			return this.reorder({ list: module.lessons, from: idx, to: idx - 1, schema: 'Lesson' })
		},

		/**
		 * @param {object} module Parent module.
		 * @param {number} idx Lesson index within module.lessons.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		moveLessonDown(module, idx) {
			return this.reorder({ list: module.lessons, from: idx, to: idx + 1, schema: 'Lesson' })
		},

		/**
		 * vuedraggable `@end` handler for the modules list — the array is
		 * already reordered by `v-model`; just persist + announce.
		 *
		 * @param {{newIndex: number}} event The drop, with the item's new index.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async onModulesDragEnd(event) {
			await this.persistOrder(this.modules, 'Course')
			this.announceMove({ list: this.modules, to: event?.newIndex ?? 0, schema: 'Course' })
		},

		/**
		 * vuedraggable `@end` handler for one module's lesson list.
		 *
		 * @param {object} module The module whose lessons were reordered.
		 * @param {{newIndex: number}} event The drop, with the item's new index.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async onLessonsDragEnd(module, event) {
			await this.persistOrder(module.lessons, 'Lesson')
			this.announceMove({ list: module.lessons, to: event?.newIndex ?? 0, schema: 'Lesson' })
		},

		/**
		 * Create a new module (child Course) at the end of the module list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async addModule() {
			if (!this.newModuleName || !this.course) return
			try {
				const created = await this.createObject('Course', {
					code: `${this.course.code || 'MOD'}-${this.modules.length + 1}`,
					name: this.newModuleName,
					level: this.course.level,
					language: this.course.language,
					parentCourseId: this.courseId,
					order: this.modules.length + 1,
					tenant_id: this.course.tenant_id,
				})
				created.lessons = []
				this.modules.push(created)
				this.newModuleName = ''
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to add module. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] addModule error', err)
			}
		},

		/**
		 * Delete a module (and, implicitly, its lessons stop being reachable
		 * from this tree — OpenRegister owns cascade/soft-delete behaviour).
		 *
		 * @param {object} module The module to delete.
		 * @param {number} idx Its index in `modules`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async deleteModule(module, idx) {
			try {
				await this.deleteObject('Course', module.id)
				this.modules.splice(idx, 1)
				await this.persistOrder(this.modules, 'Course')
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to delete module. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] deleteModule error', err)
			}
		},

		/**
		 * Create a new Lesson at the end of a module's lesson list.
		 * contentType defaults to 'text' (native, block-composed) so the
		 * lesson is immediately valid without a contentRef (design.md D2).
		 *
		 * @param {object} module The parent module.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async addLesson(module) {
			const name = this.newLessonNames[module.id]
			if (!name) return
			try {
				const created = await this.createObject('Lesson', {
					courseId: module.id,
					name,
					order: module.lessons.length + 1,
					contentType: 'text',
					blocks: [],
					tenant_id: module.tenant_id,
				})
				module.lessons.push(created)
				this.newLessonNames[module.id] = ''
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to add lesson. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] addLesson error', err)
			}
		},

		/**
		 * Delete a lesson from a module.
		 *
		 * @param {object} module The parent module.
		 * @param {number} idx The lesson's index in module.lessons.
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async deleteLesson(module, idx) {
			const lesson = module.lessons[idx]
			try {
				await this.deleteObject('Lesson', lesson.id)
				module.lessons.splice(idx, 1)
				await this.persistOrder(module.lessons, 'Lesson')
			} catch (err) {
				this.error = this.t(
					'learniq',
					'Failed to delete lesson. Please try again.',
				)
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] deleteLesson error', err)
			}
		},

		/**
		 * Capture the current Course tree into a new CourseTemplate. The
		 * server reads the tree itself and keeps module and lesson names,
		 * order and content type, with a placeholder for each rich text block
		 * (no live materialId/assessmentId/assignmentId/ltiToolPlacementId
		 * pointers, design.md D5). The source Course and its Lessons are left
		 * unchanged.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
		 */
		async saveAsTemplate() {
			if (!this.course || !this.saveTemplateForm.name) return
			this.savingTemplate = true
			this.saveTemplateError = ''
			this.saveTemplateDone = false

			try {
				const { data } = await axios.post(
					generateUrl('/apps/learniq/api/courses/{id}/save-as-template', { id: this.courseId }),
					{
						name: this.saveTemplateForm.name,
						description: this.saveTemplateForm.description || '',
					},
				)
				this.saveTemplateDone = true
				this.saveTemplateForm = { name: '', description: '' }
				if (this.$router && data?.id) {
					this.$router
						.push({
							name: 'CourseTemplateDetail',
							params: { id: data.id },
						})
						.catch(() => {})
				}
			} catch (err) {
				this.saveTemplateError = err?.response?.status === 403
					? this.t('learniq', 'You are not allowed to save course templates.')
					: this.t('learniq', 'Failed to save the template. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] saveAsTemplate error', err)
			} finally {
				this.savingTemplate = false
			}
		},

		/**
		 * Load available CourseTemplates when the instantiate panel opens.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-course-module-lesson-hierarchy-in-openregister
		 */
		async onOpenInstantiate() {
			this.showInstantiate = !this.showInstantiate
			if (this.showInstantiate && this.templates.length === 0) {
				this.templates = await this.fetchList(
					'course-template',
					'_limit=200',
				)
			}
		},

		/**
		 * Instantiate the selected CourseTemplate into a new, independent
		 * Course tree: one Course, one child Course per moduleStructure entry,
		 * one Lesson per nested lesson entry, and a CurriculumPlan when the
		 * template carries one. The server builds the tree in one request and
		 * removes what it created when a write fails. The new Course starts
		 * lifecycle draft with zero enrolments.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
		 */
		async instantiateTemplate() {
			if (!this.instantiateForm.templateId || !this.instantiateForm.name)
				return
			this.instantiating = true
			this.instantiateError = ''

			try {
				const { data } = await axios.post(
					generateUrl('/apps/learniq/api/course-templates/{id}/instantiate', {
						id: this.instantiateForm.templateId,
					}),
					{
						name: this.instantiateForm.name,
						language: this.course?.language || '',
					},
				)

				this.instantiateForm = { templateId: '', name: '' }
				this.showInstantiate = false
				if (this.$router && data?.id) {
					this.$router
						.push({
							name: 'CourseBuilder',
							params: { courseId: data.id },
						})
						.catch(() => {})
				}
			} catch (err) {
				this.instantiateError = err?.response?.status === 403
					? this.t('learniq', 'You are not allowed to create courses.')
					: this.t('learniq', 'Failed to create the course from this template. Please try again.')
				// eslint-disable-next-line no-console
				console.error('[CourseBuilder] instantiateTemplate error', err)
			} finally {
				this.instantiating = false
			}
		},
	},
}
</script>

<style scoped>
.course-builder__loading,
.course-builder__error {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.course-builder__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.course-builder__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 8px);
}

.course-builder__header-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.course-builder__panel-actions {
	margin-top: 8px;
}

.course-builder__field-label {
	display: block;
	font-weight: 500;
	margin: 8px 0 4px;
}

.course-builder__input,
.course-builder__textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	font-size: inherit;
}

.course-builder__module-list,
.course-builder__lesson-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.course-builder__module {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
}

.course-builder__module-row,
.course-builder__lesson {
	display: flex;
	align-items: center;
	gap: 6px;
}

.course-builder__lesson-list {
	margin: 8px 0 8px 24px;
	padding-left: 8px;
	border-left: 2px solid var(--color-border);
}

.course-builder__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
}

.course-builder__module-name {
	font-weight: 600;
	flex: 1 1 auto;
}

.course-builder__lesson-name {
	flex: 1 1 auto;
}

.course-builder__lesson-type {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.course-builder__icon-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 4px;
	display: inline-flex;
	align-items: center;
}

.course-builder__move-label {
	margin-inline-start: calc(var(--default-grid-baseline) * 1);
	font-size: var(--font-size-small, 13px);
}

.course-builder__reorder-hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 3);
}

.course-builder__icon-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.course-builder__icon-btn:hover:not(:disabled) {
	color: var(--color-main-text);
}

.course-builder__add-row {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	align-items: center;
}

.course-builder__inline-error {
	color: var(--color-error);
	margin-top: 8px;
}

.course-builder__inline-success {
	color: var(--color-success);
	margin-top: 8px;
}
</style>
