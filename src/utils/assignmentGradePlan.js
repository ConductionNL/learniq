// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The curriculum plan an assignment is graded under, for MarkSubmissionView
// (hermiq-ai-tooling, the same resolution as lib/Service/AssignmentGradePlan.php).
// An Assignment carries no plan of its own: the schema declares no
// curriculumPlanId or gradeScaleId, and OpenRegister drops undeclared
// properties, so reading them off the assignment always found nothing and no
// concept grade was ever created. The plan is the course's curriculumPlanId;
// the grade scale is the plan's. Pure, so tests/unit-js can import it.

/**
 * Resolve the plan and grade scale a marked submission's grade belongs to.
 *
 * @param {object} assignment The Assignment (courseId, curriculumPlanComponentId).
 * @param {function(string, string): Promise<object|null>} fetchObject Reads one learniq object by schema slug and id, null when absent or not readable.
 * @return {Promise<{curriculumPlanId: string, componentId: string, gradeScaleId: string, courseId: string}|null>} What the GradeEntry needs, or null when the assignment is not linked to a plan component through its course.
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-an-agent-grade-is-a-concept-a-teacher-publishes-req-009
 */
export async function resolveGradePlan(assignment, fetchObject) {
	const componentId = assignment?.curriculumPlanComponentId ?? ''
	const courseId = assignment?.courseId ?? ''
	if (!componentId || !courseId) {
		return null
	}

	const course = await fetchObject('course', courseId)
	const curriculumPlanId = course?.curriculumPlanId ?? ''
	if (!curriculumPlanId) {
		return null
	}

	const plan = await fetchObject('curriculum-plan', curriculumPlanId)
	if (!plan) {
		return null
	}

	return {
		curriculumPlanId,
		componentId,
		gradeScaleId: plan.gradeScaleId ?? '',
		courseId,
	}
}
