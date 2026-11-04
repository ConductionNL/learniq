// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The words Mijn leren uses for the parts of a programme. A pupil in primary
 * or secondary school takes subjects ("vakken"); everybody else takes
 * courses ("cursussen").
 *
 * @spec openspec/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
 */

/** The segments whose learners are pupils who take subjects. */
const SUBJECT_SEGMENTS = ['po', 'vo']

const COURSE_WORDS = {
	progress: '{done} of {total} mandatory courses done',
	optional: 'Optional courses',
}

const SUBJECT_WORDS = {
	progress: '{done} of {total} mandatory subjects done',
	optional: 'Optional subjects',
}

/**
 * The catalogue keys for the progress line and the optional heading.
 *
 * @param {unknown} segment The instance's segment, as initial state hands it over.
 * @return {{progress: string, optional: string}} The two keys.
 * @spec openspec/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
 */
export function programmeWords(segment) {
	return SUBJECT_SEGMENTS.includes(segment) ? SUBJECT_WORDS : COURSE_WORDS
}
