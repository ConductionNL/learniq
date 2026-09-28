// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Lesson block helpers shared by LessonComposer and its node test.
 *
 * `serialiseLessonBlocks` is LessonComposer's former `serialisableBlocks()`,
 * moved here unchanged so a test can prove what reaches OpenRegister. The
 * draft helpers carry the AI assist drafts (lesson-ai-assist-actions): a
 * draft is a richText block with a local `assistDraft` marker that the
 * serialiser never writes.
 *
 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
 */

/**
 * The payload field each block type populates. A type missing here carries no
 * payload beyond the required trio.
 */
const PAYLOAD_FIELD = {
	richText: 'text',
	media: 'materialId',
	quiz: 'assessmentId',
	assignment: 'assignmentId',
	ltiTool: 'ltiToolPlacementId',
}

/**
 * The composer's block type for a teacher note. Notes are shown among the
 * blocks while editing, but a Lesson never stores one: every signed-in user
 * reads a Lesson, so notes live in the staff-only `lesson-teacher-note`
 * schema (teacher-notes-protection).
 */
export const TEACHER_NOTE = 'teacherNote'

/** The staff-only schema teacher notes are stored in. */
export const TEACHER_NOTE_SCHEMA = 'lesson-teacher-note'

/**
 * The `blocks` array as the Lesson schema will accept it: every block
 * keeps `blockId`/`type`/`order` (its required trio) plus ONLY the
 * payload field its type actually populates.
 *
 * ⚠️ Do not send the unused payload fields as `null`. The composer seeds
 * all four pointers to null for editing convenience, and saving that
 * shape verbatim was rejected:
 *
 *   Property 'blocks.0.materialId' should be type 'string' but is
 *   'null'. Please provide a value of the correct type.
 *
 * The schema marks `materialId` `nullable: true`, but it also carries
 * `$ref: "Material"`, and OpenRegister's validator does not apply
 * `nullable` to a `$ref`-bearing property, so an explicit null fails
 * where an ABSENT key is fine. The schema's own wording ("Each block
 * carries exactly one payload matching its type") describes the shape
 * this produces, so omitting is the intended contract, not a
 * workaround.
 *
 * Applied at the save boundary so blocks LOADED from the server, which may
 * already carry nulls from earlier writes, are normalised too. Every other
 * key (such as the local `assistDraft` marker) is dropped, and so is every
 * teacher note block (teacher-notes-protection).
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {Array<object>} Blocks safe to persist.
 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-a-learner-can-read-cannot-hold-a-teacher-note
 */
export function serialiseLessonBlocks(blocks) {
	// A teacher note never reaches a Lesson, whatever the caller passes.
	return (blocks ?? [])
		.filter((block) => block?.type !== TEACHER_NOTE)
		.map((block) => {
			const serialised = {
				blockId: block.blockId,
				type: block.type,
				order: block.order,
			}
			const field = PAYLOAD_FIELD[block.type]
			// `null`/`undefined` are dropped; '' is a legitimate value for a
			// richText block the author deliberately emptied.
			if (field && block[field] !== null && block[field] !== undefined) {
				serialised[field] = block[field]
			}
			return serialised
		})
}

/**
 * A richText block holding an AI draft, marked so the composer shows it as a
 * draft and refuses to save until the teacher keeps or discards it.
 *
 * @param {{blockId: string, text: string, action: string, provider: string|null}} draft The draft.
 * @return {object} The block.
 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
 */
export function makeDraftBlock({ blockId, text, action, provider }) {
	return {
		blockId,
		type: 'richText',
		order: 0,
		text: String(text ?? ''),
		materialId: null,
		assessmentId: null,
		assignmentId: null,
		ltiToolPlacementId: null,
		assistDraft: {
			action: String(action ?? ''),
			provider: provider ? String(provider) : null,
		},
	}
}

/**
 * Keep a draft: it becomes an ordinary richText block, in place.
 *
 * @param {object} block A draft block.
 * @return {object} The same block, without its draft marker.
 * @spec openspec/specs/course-management/spec.md#scenario-a-teacher-keeps-an-ai-outline
 */
export function keepDraftBlock(block) {
	delete block.assistDraft
	return block
}

/**
 * How many blocks are still pending AI drafts.
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {number} The count.
 * @spec openspec/specs/course-management/spec.md#scenario-a-pending-draft-blocks-the-save
 */
export function countPendingDrafts(blocks) {
	return (blocks ?? []).filter((b) => Boolean(b?.assistDraft)).length
}

/**
 * The blocks the lesson player renders, in order: every block except a
 * teacher note, which stays in the composer (office-file-lesson-onboarding).
 *
 * @param {Array<object>} blocks The lesson's blocks.
 * @return {Array<object>} Sorted blocks without teacher notes.
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-notes-stay-out-of-the-player
 */
export function playerVisibleBlocks(blocks) {
	return (blocks ?? [])
		.filter((b) => b?.type !== TEACHER_NOTE)
		.slice()
		.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
}

/**
 * Split the composer's list into the lesson's blocks and its teacher notes.
 * Each note is anchored to the lesson block before it (`afterBlockId`, '' when
 * it comes first) and numbered among the notes after that block
 * (`position`). The composer list order is the display order.
 *
 * @param {Array<object>} blocks The composer's blocks, notes included.
 * @return {{blocks: Array<object>, notes: Array<{blockId: string, afterBlockId: string, position: number, text: string, noteId: (string|null)}>}} The split.
 * @spec openspec/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store
 */
export function splitTeacherNotes(blocks) {
	const lessonBlocks = []
	const notes = []
	const positions = {}
	let anchor = ''
	for (const block of blocks ?? []) {
		if (block?.type !== TEACHER_NOTE) {
			lessonBlocks.push(block)
			anchor = block?.blockId ?? ''
			continue
		}
		const position = positions[anchor] ?? 0
		positions[anchor] = position + 1
		notes.push({
			blockId: block.blockId,
			afterBlockId: anchor,
			position,
			text: String(block.text ?? ''),
			noteId: block.noteId ?? null,
		})
	}
	return { blocks: lessonBlocks, notes }
}

/**
 * The composer's list: the lesson's blocks in their order, each followed by
 * its notes in position order. A note whose anchor is empty or no longer
 * exists comes first, so no note disappears from the editor.
 *
 * @param {Array<object>} lessonBlocks The lesson's blocks.
 * @param {Array<object>} notes The lesson's `lesson-teacher-note` objects.
 * @return {Array<object>} Blocks with note blocks in place.
 * @spec openspec/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store
 */
export function mergeTeacherNotes(lessonBlocks, notes) {
	const ordered = (lessonBlocks ?? [])
		.filter((b) => b?.type !== TEACHER_NOTE)
		.slice()
		.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
	const anchors = new Set(ordered.map((b) => b.blockId))
	const byAnchor = {}
	for (const note of notes ?? []) {
		const anchor = anchors.has(note?.afterBlockId) ? note.afterBlockId : ''
		;(byAnchor[anchor] ??= []).push(note)
	}
	const asBlock = (note) => ({
		blockId: note.blockId,
		type: TEACHER_NOTE,
		order: 0,
		text: String(note.text ?? ''),
		noteId: note.id ?? note.uuid ?? note['@self']?.id ?? null,
	})
	const notesAfter = (anchor) =>
		(byAnchor[anchor] ?? [])
			.slice()
			.sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
			.map(asBlock)
	const merged = [...notesAfter('')]
	for (const block of ordered) {
		merged.push(block, ...notesAfter(block.blockId))
	}
	return merged
}

/**
 * What to write to the note store: notes to create, notes whose text or place
 * changed, and stored notes the teacher removed. Matched by `blockId`.
 *
 * @param {Array<object>} loaded The notes as loaded (`lesson-teacher-note` objects).
 * @param {Array<object>} current The notes from splitTeacherNotes().
 * @return {{create: Array<object>, update: Array<object>, remove: Array<string>}} The writes; update entries carry `id`, remove holds ids.
 * @spec openspec/specs/course-management/spec.md#requirement-the-composer-shows-notes-inline-and-saves-them-to-the-staff-store
 */
export function diffTeacherNotes(loaded, current) {
	const idOf = (note) => note?.id ?? note?.uuid ?? note?.['@self']?.id ?? null
	const stored = new Map((loaded ?? []).map((note) => [note.blockId, note]))
	const create = []
	const update = []
	for (const note of current ?? []) {
		const fields = {
			blockId: note.blockId,
			afterBlockId: note.afterBlockId,
			position: note.position,
			text: note.text,
		}
		const before = stored.get(note.blockId)
		if (!before) {
			create.push(fields)
			continue
		}
		stored.delete(note.blockId)
		if (
			before.text !== fields.text
			|| (before.afterBlockId ?? '') !== fields.afterBlockId
			|| (before.position ?? 0) !== fields.position
		) {
			update.push({ ...fields, id: idOf(before) })
		}
	}
	const remove = [...stored.values()].map(idOf).filter((id) => id)
	return { create, update, remove }
}
