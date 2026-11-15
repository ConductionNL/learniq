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
	const link = planLink(assignment)
	if (!isLinked(link)) {
		return null
	}
	link.curriculumPlanId = await coursePlanId(link.courseId, fetchObject)
	if (!link.curriculumPlanId) {
		return null
	}
	const plan = await fetchObject('curriculum-plan', link.curriculumPlanId)
	if (!plan) {
		return null
	}
	return gradePlanFor(link, plan)
}

/**
 * The component and course an assignment points at, '' when unset.
 *
 * @param {object|null} assignment The Assignment.
 * @return {{componentId: string, courseId: string, curriculumPlanId: string}} The link; the plan id is filled in later.
 */
function planLink(assignment) {
	return {
		componentId: assignment?.curriculumPlanComponentId ?? '',
		courseId: assignment?.courseId ?? '',
		curriculumPlanId: '',
	}
}

/**
 * Whether an assignment names both a plan component and a course.
 *
 * @param {{componentId: string, courseId: string}} link Output of planLink().
 * @return {boolean}
 */
function isLinked(link) {
	return !(!link.componentId || !link.courseId)
}

/**
 * The curriculum plan id of a course, '' when the course has none or is unreadable.
 *
 * @param {string} courseId The Course id.
 * @param {function(string, string): Promise<object|null>} fetchObject Reads one learniq object.
 * @return {Promise<string>}
 */
async function coursePlanId(courseId, fetchObject) {
	const course = await fetchObject('course', courseId)
	return course?.curriculumPlanId ?? ''
}

/**
 * What the GradeEntry needs from the link and the plan.
 *
 * @param {{componentId: string, courseId: string, curriculumPlanId: string}} link The resolved link.
 * @param {object} plan The CurriculumPlan.
 * @return {{curriculumPlanId: string, componentId: string, gradeScaleId: string, courseId: string}}
 */
function gradePlanFor(link, plan) {
	return {
		curriculumPlanId: link.curriculumPlanId,
		componentId: link.componentId,
		gradeScaleId: plan.gradeScaleId ?? '',
		courseId: link.courseId,
	}
}
