// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Where a cohort is thin: for each required competency, how many of the
 * cohort's learners have not yet reached their framework's target level.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/competency/spec.md#requirement-a-group-view-shows-which-skills-are-thin-across-a-cohort
 */

/**
 * The order of a proficiency level within its framework, or null when unknown.
 *
 * @param {object|undefined} framework The CompetencyFramework.
 * @param {string|null|undefined} levelId The level.
 * @return {number|null}
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/competency/spec.md#requirement-a-group-view-shows-which-skills-are-thin-across-a-cohort
 */
function levelOrder(framework, levelId) {
	if (!levelId) {
		return null
	}

	const level = (framework?.proficiencyLevels ?? []).find((l) => l.levelId === levelId)
	return typeof level?.order === 'number' ? level.order : null
}

/**
 * Whether an attained level reaches the framework's target. Without a target
 * level (or with an unknown one), any recorded level counts as reached.
 *
 * @param {object|undefined} framework The CompetencyFramework.
 * @param {string|null|undefined} levelId The attained level.
 * @return {boolean}
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/competency/spec.md#requirement-a-group-view-shows-which-skills-are-thin-across-a-cohort
 */
export function reachesTarget(framework, levelId) {
	if (!levelId) {
		return false
	}

	const target = levelOrder(framework, framework?.targetLevelId)
	if (target === null) {
		return true
	}

	const attained = levelOrder(framework, levelId)
	return attained !== null && attained >= target
}

/**
 * The required competencies with the number of the cohort's learners below
 * the target level, the thinnest first (then by code).
 *
 * @param {object} input The cohort's data.
 * @param {string[]} input.learnerIds The cohort's learners (Nextcloud user ids).
 * @param {object[]} input.competencies The competencies the cohort's programme requires.
 * @param {object[]} input.attainments The learners' CompetencyAttainment rows.
 * @param {object[]} input.frameworks The CompetencyFrameworks the competencies belong to.
 * @return {Array<{id: string, code: string, title: string, below: number, total: number}>}
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/competency/spec.md#requirement-a-group-view-shows-which-skills-are-thin-across-a-cohort
 */
export function cohortSkillsGap({ learnerIds, competencies, attainments, frameworks }) {
	const learners = [...new Set((learnerIds ?? []).filter(Boolean))]
	const frameworkById = Object.fromEntries((frameworks ?? []).map((f) => [f.id ?? f.uuid, f]))

	const reached = new Set()
	for (const row of attainments ?? []) {
		const framework = frameworkById[row.frameworkId]
		if (learners.includes(row.learnerId) && reachesTarget(framework, row.proficiencyLevelId)) {
			reached.add(`${row.learnerId}|${row.competencyId}`)
		}
	}

	return (competencies ?? [])
		.map((competency) => {
			const id = competency.id ?? competency.uuid
			const below = learners.filter((learner) => !reached.has(`${learner}|${id}`)).length
			return { id, code: competency.code ?? id, title: competency.title ?? '', below, total: learners.length }
		})
		.sort((a, b) => b.below - a.below || String(a.code).localeCompare(String(b.code)))
}
