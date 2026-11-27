// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Lesson block helpers shared by LessonComposer and its node test.
 *
 * `serialiseLessonBlocks` is LessonComposer's former `serialisableBlocks()`,
 * moved here unchanged so a test can prove what reaches OpenRegister. The
 * draft helpers carry the AI assist drafts (lesson-ai-assist-actions): a
 * draft is a richText block with a local `draft` marker (CnBlockListEditor's,
 * which offers Keep and Discard) that the serialiser never writes.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
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
 * key (such as the local `draft` marker) is dropped, and so is every
 * teacher note block (teacher-notes-protection).
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {Array<object>} Blocks safe to persist.
 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
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
 * draft and refuses to save until the teacher keeps or discards it. `note` is
 * the line CnBlockListEditor shows beside Keep and Discard.
 *
 * @param {{blockId: string, text: string, action: string, provider: string|null, note: string}} draft The draft.
 * @return {object} The block.
 * @spec openspec/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
 */
export function makeDraftBlock({ blockId, text, action, provider, note }) {
	return {
		blockId,
		type: 'richText',
		order: 0,
		text: String(text ?? ''),
		materialId: null,
		assessmentId: null,
		assignmentId: null,
		ltiToolPlacementId: null,
		draft: {
			note: String(note ?? ''),
			action: String(action ?? ''),
			provider: provider ? String(provider) : null,
		},
	}
}

/**
 * How many blocks are still pending AI drafts.
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {number} The count.
 * @spec openspec/specs/course-management/spec.md#scenario-a-pending-draft-blocks-the-save
 */
export function countPendingDrafts(blocks) {
	return (blocks ?? []).filter((b) => Boolean(b?.draft)).length
}

/**
 * The blocks the lesson player renders, in order: every block except a
 * teacher note, which stays in the composer (office-file-lesson-onboarding).
 *
 * @param {Array<object>} blocks The lesson's blocks.
 * @return {Array<object>} Sorted blocks without teacher notes.
 * @spec openspec/specs/course-management/spec.md#scenario-notes-stay-out-of-the-player
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
	const nextPosition = notePositions()
	let anchor = ''
	for (const block of blocks ?? []) {
		if (block?.type !== TEACHER_NOTE) {
			lessonBlocks.push(block)
			anchor = block?.blockId ?? ''
			continue
		}
		notes.push(placedNote(block, { afterBlockId: anchor, position: nextPosition(anchor) }))
	}
	return { blocks: lessonBlocks, notes }
}

/**
 * A counter per anchor: each call returns the next note position after it,
 * starting at 0.
 *
 * @return {function(string): number} The counter.
 */
function notePositions() {
	const positions = {}
	return (anchor) => {
		const position = positions[anchor] ?? 0
		positions[anchor] = position + 1
		return position
	}
}

/**
 * A note block as a note at its place among the lesson blocks.
 *
 * @param {object} block A `teacherNote` composer block.
 * @param {{afterBlockId: string, position: number}} place Its anchor and position.
 * @return {{blockId: string, afterBlockId: string, position: number, text: string, noteId: (string|null)}} The note.
 */
function placedNote(block, place) {
	return {
		blockId: block.blockId,
		afterBlockId: place.afterBlockId,
		position: place.position,
		text: String(block.text ?? ''),
		noteId: block.noteId ?? null,
	}
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
	const writes = { stored: storedNotesByBlockId(loaded), create: [], update: [] }
	for (const note of current ?? []) {
		sortNoteWrite(note, writes)
	}
	const remove = [...writes.stored.values()].map(storedNoteId).filter((id) => id)
	return { create: writes.create, update: writes.update, remove }
}

/**
 * The id of a stored note, wherever the object carries it.
 *
 * @param {object} note A `lesson-teacher-note` object.
 * @return {string|null} The id.
 */
function storedNoteId(note) {
	return note?.id ?? note?.uuid ?? note?.['@self']?.id ?? null
}

/**
 * The loaded notes keyed by their `blockId`.
 *
 * @param {Array<object>} loaded The notes as loaded.
 * @return {Map<string, object>} The notes by block id.
 */
function storedNotesByBlockId(loaded) {
	return new Map((loaded ?? []).map((note) => [note.blockId, note]))
}

/**
 * Put one current note among the writes: a create when it is new, an update
 * when its text or place changed. A matched note leaves `stored`, so what is
 * left there was removed.
 *
 * @param {object} note A note from splitTeacherNotes().
 * @param {{stored: Map<string, object>, create: Array<object>, update: Array<object>}} writes The diff being built.
 */
function sortNoteWrite(note, writes) {
	const fields = noteWriteFields(note)
	const before = writes.stored.get(note.blockId)
	if (!before) {
		writes.create.push(fields)
		return
	}
	writes.stored.delete(note.blockId)
	if (noteChanged(before, fields)) {
		writes.update.push({ ...fields, id: storedNoteId(before) })
	}
}

/**
 * The fields of a note that the store holds.
 *
 * @param {object} note A note from splitTeacherNotes().
 * @return {{blockId: string, afterBlockId: string, position: number, text: string}} The fields.
 */
function noteWriteFields(note) {
	return {
		blockId: note.blockId,
		afterBlockId: note.afterBlockId,
		position: note.position,
		text: note.text,
	}
}

/**
 * Whether a stored note's text or place differs from the current one.
 *
 * @param {object} before The stored note.
 * @param {{afterBlockId: string, position: number, text: string}} fields The current note.
 * @return {boolean}
 */
function noteChanged(before, fields) {
	return (
		before.text !== fields.text
		|| (before.afterBlockId ?? '') !== fields.afterBlockId
		|| (before.position ?? 0) !== fields.position
	)
}

/** The block types a teacher adds in the composer, in palette order. */
export const BLOCK_TYPES = ['richText', 'media', 'quiz', 'assignment', 'ltiTool', 'teacherNote']

/**
 * A client-side block id: a UUID where the browser has one.
 *
 * @return {string} The id.
 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
 */
export function newBlockId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

/**
 * The fields of a new, empty block of one type: text types start empty, the
 * picker types without a choice.
 *
 * @param {string} type A BLOCK_TYPES value.
 * @return {object} The block's fields, with a fresh `blockId`.
 * @spec openspec/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
 */
export function emptyBlock(type) {
	return {
		blockId: newBlockId(),
		order: 0,
		text: ['richText', TEACHER_NOTE].includes(type) ? '' : null,
		materialId: null,
		assessmentId: null,
		assignmentId: null,
		ltiToolPlacementId: null,
	}
}
