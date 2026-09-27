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
 * key (such as the local `assistDraft` marker) is dropped.
 *
 * @param {Array<object>} blocks The composer's blocks.
 * @return {Array<object>} Blocks safe to persist.
 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lesson-s-body-is-authored-as-an-ordered-list-of-typed-content-blocks
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
 */
export function serialiseLessonBlocks(blocks) {
	return (blocks ?? []).map((block) => {
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
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards
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
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-a-teacher-keeps-an-ai-outline
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
 * @spec openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#scenario-a-pending-draft-blocks-the-save
 */
export function countPendingDrafts(blocks) {
	return (blocks ?? []).filter((b) => Boolean(b?.assistDraft)).length
}
