// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who may act on an exam-board case. The server withholds a fraud case's
 * hearing records and decision from everyone outside the exam board; these
 * gates keep the decision forms away from the same readers.
 *
 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
 */

import { canSeeHandInStatus } from './handInStatus.js'

/** The `dashboardRoles` entry DashboardRoleService gives exam board members. */
export const EXAM_BOARD_ROLE = 'exam-board'

/**
 * Whether the signed-in user is on the exam board.
 *
 * @param {string[]} dashboardRoles The `dashboardRoles` initial state.
 * @return {boolean} True for exam board members and admins.
 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
 */
export function isExamBoard(dashboardRoles) {
	return Array.isArray(dashboardRoles) && dashboardRoles.includes(EXAM_BOARD_ROLE)
}

/**
 * Whether the signed-in user may withdraw an exemption request: the exam
 * board, or staff filing on a learner's behalf.
 *
 * @param {string[]} dashboardRoles The `dashboardRoles` initial state.
 * @return {boolean} True for the exam board and staff.
 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
 */
export function canWithdrawExemption(dashboardRoles) {
	return isExamBoard(dashboardRoles) || canSeeHandInStatus(dashboardRoles)
}
