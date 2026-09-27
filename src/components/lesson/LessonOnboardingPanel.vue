<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  LessonOnboardingPanel.vue
  The lesson onboarding section of the "Import course package" page
  (CoursePackageImportView), office-file-lesson-onboarding. A section, not
  a page of its own: bringing existing lessons in is one job, and the app's
  custom-page count stays where it is (gate 69).

  A teacher chooses one folder in their Nextcloud files. Learniq records each
  Word or PowerPoint file created there as a LessonOnboardingFile row and
  notifies the teacher; the notification links to this page. This page lists the
  teacher's detected files, and for each one the teacher picks a course and
  confirms the import, or dismisses the file (decision D17). The warning that
  a lesson is visible to the whole school sits next to the import action,
  because the confirmation is where a file with pupil data must be stopped.

  Talks to:
    - GET/PUT /apps/learniq/api/lesson-onboarding/folder (the setting)
    - GET /apps/openregister/api/objects/learniq/lesson-onboarding-file (own rows)
    - PATCH .../lesson-onboarding-file/:id {lifecycle: dismissed}
    - POST /apps/learniq/api/lesson-onboarding/files/:id/import {courseId}
    - GET /apps/openregister/api/objects/learniq/course (the course picker)

  @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
  @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
-->
<template>
	<section
		id="lesson-onboarding"
		class="lesson-onboarding"
		aria-labelledby="lo-heading">
		<header class="lesson-onboarding__header">
			<h2 id="lo-heading">
				{{ t('learniq', 'Lessons from Word and PowerPoint') }}
			</h2>
			<p class="lesson-onboarding__intro">
				{{
					t(
						'learniq',
						'Put Word (.docx) and PowerPoint (.pptx) files in one folder. Learniq lists each new file here and sends you a notification. Nothing is read until you import a file.',
					)
				}}
			</p>
		</header>

		<p class="lesson-onboarding__sr-live" aria-live="polite" role="status">
			{{ liveMessage }}
		</p>

		<div v-if="loading" class="lesson-onboarding__loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('learniq', 'Loading your files…') }}</span>
		</div>

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else>
			<section
				class="lesson-onboarding__section"
				aria-labelledby="lo-folder-heading">
				<h3 id="lo-folder-heading">
					{{ t('learniq', 'Your onboarding folder') }}
				</h3>
				<p v-if="folder.path">
					{{
						t('learniq', 'Learniq watches this folder: {path}', {
							path: folder.path,
						})
					}}
				</p>
				<p v-else>
					{{ t('learniq', 'You have not chosen a folder yet.') }}
				</p>
				<p v-if="folderError" class="lesson-onboarding__error" role="alert">
					{{ folderError }}
				</p>
				<div class="lesson-onboarding__actions">
					<NcButton :disabled="savingFolder" @click="chooseFolder">
						{{ t('learniq', 'Choose folder') }}
					</NcButton>
					<NcButton
						v-if="folder.path"
						variant="tertiary"
						:disabled="savingFolder"
						@click="stopWatching">
						{{ t('learniq', 'Stop watching') }}
					</NcButton>
				</div>
			</section>

			<section
				class="lesson-onboarding__section"
				aria-labelledby="lo-files-heading">
				<h3 id="lo-files-heading">
					{{ t('learniq', 'Files waiting for you') }}
				</h3>

				<NcNoteCard type="warning">
					{{
						t(
							'learniq',
							'A lesson is visible to everyone in the school. Import only lesson material: a file with pupil names, marks or notes about a pupil does not belong in a lesson.',
						)
					}}
				</NcNoteCard>

				<NcEmptyContent
					v-if="rows.length === 0"
					:name="t('learniq', 'No new files')"
					:description="
						t(
							'learniq',
							'Drop a Word or PowerPoint file in your folder to see it here.',
						)
					" />

				<ul v-else class="lesson-onboarding__rows">
					<li
						v-for="row in rows"
						:key="rowId(row)"
						class="lesson-onboarding__row"
						:aria-busy="busyRow === rowId(row) ? 'true' : 'false'">
						<div class="lesson-onboarding__file">
							<strong>{{ row.fileName }}</strong>
							<span class="lesson-onboarding__meta">
								{{
									t('learniq', '{format} file, found {date}', {
										format: formatLabel(row.format),
										date: formatDate(row.detectedAt),
									})
								}}
							</span>
						</div>
						<NcSelect
							v-model="courseChoice[rowId(row)]"
							:options="courseOptions"
							:reduce="(opt) => opt.id"
							:inputLabel="
								t('learniq', 'Course for {name}', {
									name: row.fileName,
								})
							"
							:placeholder="t('learniq', 'Choose a course')" />
						<p
							v-if="rowErrors[rowId(row)]"
							class="lesson-onboarding__error"
							role="alert">
							{{ rowErrors[rowId(row)] }}
						</p>
						<div class="lesson-onboarding__actions">
							<NcButton
								variant="primary"
								:disabled="busyRow !== ''"
								@click="importRow(row)">
								<template #icon>
									<NcLoadingIcon
										v-if="busyRow === rowId(row)"
										:size="20" />
								</template>
								{{ t('learniq', 'Import as lesson draft') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busyRow !== ''"
								@click="dismissRow(row)">
								{{ t('learniq', 'Dismiss') }}
							</NcButton>
						</div>
					</li>
				</ul>
			</section>

			<section
				v-if="results.length > 0 || imported.length > 0"
				class="lesson-onboarding__section"
				aria-labelledby="lo-imported-heading">
				<h3 id="lo-imported-heading">
					{{ t('learniq', 'Imported as lesson drafts') }}
				</h3>
				<ul class="lesson-onboarding__rows">
					<li
						v-for="result in results"
						:key="result.lessonId"
						class="lesson-onboarding__row">
						<p>
							{{
								t(
									'learniq',
									'Lesson draft "{name}" created with {blocks} blocks.',
									{
										name: result.lessonName,
										blocks: result.blocks,
									},
								)
							}}
						</p>
						<ul
							v-if="result.notes.length > 0"
							class="lesson-onboarding__notes">
							<li v-for="note in result.notes" :key="note">
								{{ note }}
							</li>
						</ul>
						<NcButton
							@click="openLesson(result.courseId, result.lessonId)">
							{{ t('learniq', 'Open in the lesson composer') }}
						</NcButton>
					</li>
					<li
						v-for="row in imported"
						:key="rowId(row)"
						class="lesson-onboarding__row">
						<span>{{ row.fileName }}</span>
						<NcButton
							v-if="row.lessonId && row.courseId"
							variant="tertiary"
							@click="openLesson(row.courseId, row.lessonId)">
							{{ t('learniq', 'Open lesson') }}
						</NcButton>
					</li>
				</ul>
			</section>
		</template>
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import {
	dismissRequest,
	formatLabel,
	importErrorKey,
	importRequest,
	ONBOARDING_API,
	rowsFrom,
	rowsUrl,
} from '../../utils/lessonOnboarding.js'

export default {
	name: 'LessonOnboardingPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			loadError: '',
			folder: { folderId: null, path: null },
			folderError: '',
			savingFolder: false,
			/** @type {Array<object>} Detected rows of the signed-in teacher. */
			rows: [],
			/** @type {Array<object>} Recently imported rows. */
			imported: [],
			/** @type {Array<object>} Courses for the picker. */
			courses: [],
			/** @type {{[key: string]: string}} Row id to chosen course id. */
			courseChoice: {},
			/** @type {{[key: string]: string}} Row id to its error message. */
			rowErrors: {},
			/** @type {Array<object>} Imports done on this page. */
			results: [],
			busyRow: '',
			liveMessage: '',
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, label: string}>} Course picker options.
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
		 */
		courseOptions() {
			return this.courses.map((c) => ({
				id: this.rowId(c),
				label: c.name || c.code || this.rowId(c),
			}))
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		formatLabel,

		/**
		 * An OpenRegister object's id, whatever envelope it came in.
		 *
		 * @param {object} object The object.
		 * @return {string} The id.
		 * @spec exclude Envelope accessor shared by every list on this page.
		 */
		rowId(object) {
			return String(object?.id ?? object?.['@self']?.id ?? '')
		},

		/**
		 * A detection date for display.
		 *
		 * @param {string|null} value ISO date-time.
		 * @return {string} A local date, or ''.
		 * @spec exclude Presentation-only date formatting.
		 */
		formatDate(value) {
			const date = value ? new Date(value) : null
			if (!date || Number.isNaN(date.getTime())) return ''
			return date.toLocaleDateString()
		},

		/**
		 * Load the folder, the teacher's rows and the courses.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			const userId = getCurrentUser()?.uid ?? ''
			try {
				const [folder, detected, imported, courses] = await Promise.all([
					axios.get(generateUrl(`${ONBOARDING_API}/folder`)),
					axios.get(generateUrl(rowsUrl(userId, 'detected'))),
					axios.get(generateUrl(rowsUrl(userId, 'imported', 20))),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/learniq/course?_limit=500',
						),
					),
				])
				this.folder = folder.data ?? { folderId: null, path: null }
				this.rows = rowsFrom(detected.data)
				this.imported = rowsFrom(imported.data)
				this.courses = rowsFrom(courses.data)
			} catch (err) {
				this.loadError = this.t(
					'learniq',
					'Your files could not be loaded. Try again later.',
				)
				// eslint-disable-next-line no-console
				console.error('[LessonOnboardingPanel] load error', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Pick a folder in the teacher's files and store it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-picks-a-folder
		 */
		async chooseFolder() {
			let path
			try {
				path = await getFilePickerBuilder(
					this.t('learniq', 'Choose your onboarding folder'),
				)
					.setMultiSelect(false)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.addButton({
						label: this.t('learniq', 'Use this folder'),
						type: 'primary',
						callback: () => {},
					})
					.build()
					.pick()
			} catch {
				// Closing the picker is not an error the teacher needs to see.
				return
			}
			await this.saveFolder(Array.isArray(path) ? path[0] : path)
		},

		/**
		 * Stop watching: clear the setting.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
		 */
		stopWatching() {
			return this.saveFolder('')
		},

		/**
		 * Store the folder setting.
		 *
		 * @param {string} path Path in the teacher's files, or '' to clear.
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
		 */
		async saveFolder(path) {
			this.savingFolder = true
			this.folderError = ''
			try {
				const resp = await axios.put(
					generateUrl(`${ONBOARDING_API}/folder`),
					{
						path: path ?? '',
					},
				)
				this.folder = resp.data
				this.liveMessage = resp.data.path
					? this.t('learniq', 'Learniq now watches {path}.', {
							path: resp.data.path,
						})
					: this.t('learniq', 'Learniq no longer watches a folder.')
			} catch (err) {
				this.folderError =
					err?.response?.status === 400
						? this.t(
								'learniq',
								'Choose one folder inside your files, not a file and not all of your files.',
							)
						: this.t(
								'learniq',
								'The folder could not be saved. Try again later.',
							)
			} finally {
				this.savingFolder = false
			}
		},

		/**
		 * Import one confirmed file into the chosen course.
		 *
		 * @param {object} row The detected row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
		 */
		async importRow(row) {
			const id = this.rowId(row)
			const courseId = this.courseChoice[id]
			this.rowErrors = { ...this.rowErrors, [id]: '' }
			if (!courseId) {
				this.rowErrors = {
					...this.rowErrors,
					[id]: this.errorMessage('no-course'),
				}
				return
			}

			this.busyRow = id
			try {
				const request = importRequest(id, courseId)
				const resp = await axios.post(generateUrl(request.url), request.body)
				this.results.unshift({ ...resp.data, notes: resp.data.notes ?? [] })
				this.rows = this.rows.filter((r) => this.rowId(r) !== id)
				this.liveMessage = this.t(
					'learniq',
					'Lesson draft "{name}" created.',
					{ name: resp.data.lessonName },
				)
			} catch (err) {
				const key = importErrorKey(
					err?.response?.status ?? 0,
					err?.response?.data ?? null,
				)
				this.rowErrors = { ...this.rowErrors, [id]: this.errorMessage(key) }
			} finally {
				this.busyRow = ''
			}
		},

		/**
		 * Dismiss one file without reading it.
		 *
		 * @param {object} row The detected row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-dismisses-a-file
		 */
		async dismissRow(row) {
			const id = this.rowId(row)
			this.busyRow = id
			try {
				const request = dismissRequest(id)
				await axios.patch(generateUrl(request.url), request.body)
				this.rows = this.rows.filter((r) => this.rowId(r) !== id)
				this.liveMessage = this.t('learniq', '{name} dismissed.', {
					name: row.fileName,
				})
			} catch {
				this.rowErrors = {
					...this.rowErrors,
					[id]: this.t(
						'learniq',
						'The file could not be dismissed. Try again later.',
					),
				}
			} finally {
				this.busyRow = ''
			}
		},

		/**
		 * The translated message for an import error key.
		 *
		 * @param {string} key From importErrorKey().
		 * @return {string} The message.
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-openregister-has-no-presentation-reader-yet
		 */
		errorMessage(key) {
			const messages = {
				'reader-unavailable': this.t(
					'learniq',
					'PowerPoint import needs a newer OpenRegister. The file stays in your list.',
				),

				'file-gone': this.t(
					'learniq',
					'The file is no longer in your files.',
				),

				'not-detected': this.t(
					'learniq',
					'This file was already imported or dismissed.',
				),

				'not-found': this.t('learniq', 'This file is not in your list.'),
				'course-not-found': this.t(
					'learniq',
					'That course does not exist, or you cannot see it.',
				),

				unreadable: this.t(
					'learniq',
					'This file holds no lesson content learniq can read.',
				),

				'no-course': this.t('learniq', 'Choose a course first.'),
			}
			return (
				messages[key]
				?? this.t('learniq', 'The import failed. Try again later.')
			)
		},

		/**
		 * Open a lesson in the composer.
		 *
		 * @param {string} courseId The course.
		 * @param {string} lessonId The lesson.
		 * @return {void}
		 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
		 */
		openLesson(courseId, lessonId) {
			if (this.$router) {
				this.$router
					.push({ name: 'LessonComposer', params: { courseId, lessonId } })
					.catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.lesson-onboarding {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.lesson-onboarding__intro,
.lesson-onboarding__meta {
	color: var(--color-text-maxcontrast);
}

.lesson-onboarding__sr-live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.lesson-onboarding__loading {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-onboarding__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-onboarding__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-onboarding__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-onboarding__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.lesson-onboarding__file {
	display: flex;
	flex-direction: column;
}

.lesson-onboarding__error {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}

.lesson-onboarding__notes {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 5);
	color: var(--color-text-maxcontrast);
}
</style>
