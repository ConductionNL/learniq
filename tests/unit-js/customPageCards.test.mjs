// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A custom page frames its sections as its board draws them: each section is
// a nextcloud-vue CnDetailCard, so the board look gives it its border, title,
// insets and radius, and the page lays the cards out with the board's content
// inset and section gap (`lq-board-page` in src/assets/app.css).
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

/**
 * Custom views whose board draws their sections as cards, with the number of
 * CnDetailCard elements the template carries (a v-for card counts once).
 */
const views = {
	BsaRiskDashboard: 1,
	CourseQualityReport: 2,
	EnrolmentForecastView: 3,
	EvaluationCampaignResultsView: 1,
	ExternalTrainingBulkRecordView: 3,
	LearningRecordImportView: 2,
	LearniqAccessibilityStatement: 1,
	RolloverWizard: 3,
	RoomUtilisationReport: 3,
	SkillsGapDashboard: 3,
	MyElectives: 1,
	MyEvaluationsView: 2,
	MyWorkGroups: 1,
	CheckInPage: 1,
	ProctoringReviewQueue: 1,
	PupilDossierTimelineView: 2,
	CurriculumCoverageMatrixView: 3,
	ItemAnalysisView: 2,
	PeerReviewMarkingView: 3,
	SubjectChoicePicker: 1,
	MyLearningRecordView: 10,
	GroupTrendHeatmap: 1,
	AllocateMarkersView: 1,
	ContactHoursReport: 2,
	BookConferenceSlotsView: 1,
	AdmissionsReviewBoard: 1,
	TimetableConflictQueue: 1,
	LearniqAiProcessingDisclosure: 2,
	AssessmentScoringView: 1,
	ExportRequestView: 1,
	LessonPlayer: 1,
	ConferenceScheduleBoard: 2,
	MyTimetable: 2,
	RapportvergaderingReviewView: 1,
	TimetableLookup: 2,
	ExamCaseDossierView: 2,
	PortfolioReviewView: 2,
	CoursePackageImportView: 2,
	MarkSubmissionView: 6,
	RollCallView: 1,
	LessonComposer: 1,
	CohortGradebookView: 1,
	CourseBuilder: 3,
	CourseCatalogue: 1,
	CoursePreviewView: 1,
	DisplayScreenAddressView: 1,
	ElectiveRosterView: 1,
	GradeImpactDetail: 3,
	GroupPlanSubgroupLearnerContext: 2,
	HourPlanActivities: 1,
	HourPlanEditor: 2,
	ItemAuthorView: 2,
	LeaderboardView: 1,
	LearningPlanEditorView: 1,
	LearningRecordShareVerifyView: 2,
	PortfolioBuilder: 3,
	QtiImportView: 1,
	ReissueCertificatesView: 1,
	SelfAssessmentView: 2,
	SignatureView: 1,
	StandbyPlanning: 1,
}

/**
 * The template part of a view.
 *
 * @param {string} component Component name under src/views.
 * @return {string} The source up to the script block.
 */
function template(component) {
	const source = readFileSync(resolve(root, `src/views/${component}.vue`), 'utf8')
	return source.slice(0, source.indexOf('\n<script'))
}

for (const [component, cards] of Object.entries(views)) {
	test(`${component} renders its sections in board cards`, () => {
		const markup = template(component)
		const found = markup.match(/<CnDetailCard\b/g) ?? []
		assert.equal(found.length, cards, 'CnDetailCard count')
	})

	test(`${component} lays its page out as the board does`, () => {
		const markup = template(component)
		const rootTag = markup.match(/<template>\s*<(\w+)[^>]*class="([^"]*)"/)
		assert.ok(rootTag, 'the root element has a class')
		assert.ok(rootTag[2].split(/\s+/).includes('lq-board-page'), 'root carries lq-board-page')
	})
}

test('the board page layout takes the board look tokens', () => {
	const css = readFileSync(resolve(root, 'src/assets/app.css'), 'utf8')
	const rule = css.slice(css.indexOf('.lq-board-page {'))
	assert.match(rule, /gap: var\(--cn-board-section-gap/)
	assert.match(rule, /padding: var\(--cn-board-content-padding/)
})

test('a custom page title sits 4px under the content top on the board look', () => {
	const css = readFileSync(resolve(root, 'src/assets/app.css'), 'utf8')
	const at = css.indexOf('.cn-look-board .lq-board-page .cn-page-header:not(.cn-look-nextcloud *) {')
	assert.ok(at > -1, 'the board look rule for a custom page header exists')
	const rule = css.slice(at, css.indexOf('}', at))
	assert.match(rule, /padding-top: 4px;/)
})
