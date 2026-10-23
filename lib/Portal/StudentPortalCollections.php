<?php

/**
 * Learniq StudentPortalCollections
 *
 * The read collections of the `student` audience (the learner herself), moved
 * out of PortalContributionProvider so that class stays readable and under
 * phpmd's class length: her grades, final grades and attendance, her
 * enrolments and submissions, her BPV placement and hour weeks (through
 * StudentPortalPages), her absence reports and inbox, and her tests as a timed
 * task. Every entry is scoped by the scalar `learnerRef` == her own
 * LearnerProfile UUID and field-projected to hide staff-only columns. The
 * declarations are unchanged by the move.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Declares the student audience's read collections.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class StudentPortalCollections {

	/**
	 * The OpenRegister register slug every collection below lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The learner's own result collections — grades, final grades and attendance.
	 *
	 * Every entry is scoped by `learnerRef` == the student's own LearnerProfile
	 * UUID and field-projected to hide staff-only columns.
	 *
	 * @return array<int, array<string, mixed>> Student result collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/student-portal-reads-like-the-boards/specs/portal-contribution/spec.md#requirement-the-student-pages-use-the-words-of-the-boards
	 */
	public function studentResultCollections(): array {
		return [
			[
				'id' => 'studentGrades',
				'register' => self::REGISTER,
				'schema' => 'grade-entry',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My grades',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'learnerRef',
					'courseId',
					// Readable copies and the weight (site-guardian-portal-design):
					// the subject and test a grade is for, and how often it counts.
					'courseName',
					'methodName',
					'methodBlock',
					'weight',
					'curriculumPlanId',
					'componentId',
					'value',
					'gradeScaleId',
					'period',
					'gradedAt',
				],
				// Readable headers instead of field keys (portal proof run 1,
				// defect 10): subject, date and grade, as on the board. The
				// test's own name ("Leestoets") is a curriculum-plan component
				// label with no readable copy on the grade yet.
				'columns' => [
					['field' => 'courseName', 'label' => 'Subject'],
					['field' => 'gradedAt', 'label' => 'Date', 'render' => 'date'],
					['field' => 'value', 'label' => 'Grade'],
				],
			],
			[
				'id' => 'studentFinalGrades',
				'register' => self::REGISTER,
				'schema' => 'final-grade',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My final grades',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'learnerRef',
					'courseId',
					'programmeId',
					'curriculumPlanId',
					'gradeScaleId',
					'value',
					'passed',
					'lastRecomputedAt',
				],
			],
			[
				'id' => 'studentAttendance',
				'register' => self::REGISTER,
				'schema' => 'attendance-record',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My attendance',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'learnerRef',
					'sessionId',
					'cohortId',
					'status',
					'minutesAttended',
					'markedAt',
				],
			],
		];

	}//end studentResultCollections()

	/**
	 * The learner's own activity collections — enrolments, submissions, excuses and inbox.
	 *
	 * Every entry is scoped by the scalar `learnerRef`: portaliq's direct scope
	 * compares one value, so the Submission array `learnerRefs` never matched
	 * (assignment-portal-wiring). The inbox entry carries `kind: inbox` so portaliq renders it
	 * in the shared inbox surface rather than as a plain collection.
	 *
	 * @param StudentPortalPages $site The pupil's own declarations.
	 *
	 * @return array<int, array<string, mixed>> Student activity collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function studentActivityCollections(StudentPortalPages $site): array {
		return array_merge(
			$this->studentEnrolmentAndSubmissionCollections(),
			// Her placement and her weeks of hours sit between them, which is
			// the order the pupil's pages read (internship-hours).
			$site->bpvCollections(),
			$this->studentWelfareAndInboxCollections()
		);

	}//end studentActivityCollections()

	/**
	 * What she is enrolled in and what she has handed in.
	 *
	 * @return array<int, array<string, mixed>> Two collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function studentEnrolmentAndSubmissionCollections(): array {
		return [
			[
				'id' => 'studentEnrolments',
				'register' => self::REGISTER,
				'schema' => 'enrolment',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My enrolments',
				'listable' => true,
				'fields' => [
					'learnerRef',
					'courseId',
					'mandatory',
					'dueDate',
					'source',
					'regulationSlug',
					'cohortId',
				],
				// Who she may write to: the teachers of each active group
				// (portal-message-contacts, portaliq site-messages-per-record).
				'contacts' => [
					'provider' => 'ownMessageContacts',
					'composeLabel' => 'A message to your teacher',
					'composeHint' => 'Your teacher usually answers within two school days.',
				],
			],
			[
				'id' => 'studentSubmissions',
				'register' => self::REGISTER,
				'schema' => 'submission',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My submissions',
				'listable' => true,
				// Portal-assignment-hand-in-endpoint: a per-row hand-in on the
				// pupil's drafts (portaliq contribution-pay-screen row actions).
				'rowActions' => ['handIn'],
				'fields' => [
					'learnerRef',
					'assignmentId',
					'attachmentRefs',
					'submittedAt',
					'feedbackText',
					'lifecycle',
				],
			],
		];

	}//end studentEnrolmentAndSubmissionCollections()


	/**
	 * Her absence reports and her inbox.
	 *
	 * @return array<int, array<string, mixed>> Two collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function studentWelfareAndInboxCollections(): array {
		return [
			[
				'id' => 'studentExcuseRequests',
				'register' => self::REGISTER,
				'schema' => 'excuse-request',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My absence excuses',
				'listable' => true,
				'fields' => [
					'learnerRef',
					'dateFrom',
					'dateTo',
					'reason',
					'reasonKind',
					'attachmentRef',
					'lifecycle',
					'decidedAt',
				],
			],
			// Inbox (contract v2): the learner's grade-published
			// notifications, scoped by learnerRef. Portaliq renders
			// `kind: inbox` collections in the shared inbox surface.
			[
				'id' => 'studentInbox',
				'kind' => 'inbox',
				'register' => self::REGISTER,
				'schema' => 'grade-notification',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'Notifications',
				'listable' => true,
				'fields' => [
					'learnerRef',
					'event',
					'courseId',
					'courseName',
					'visibleFrom',
				],
				// A grade held back by the teacher stays out until its moment (portaliq #1198).
				'visibleFromField' => 'visibleFrom',
				'messageFields' => [
					'subject' => 'courseName',
					'receivedAt' => 'visibleFrom',
				],
			],
		];

	}//end studentWelfareAndInboxCollections()

	/**
	 * The learner's tests as a portaliq timed task (ConductionNL/portaliq#749).
	 *
	 * The collection lists the learner's own attempts, scoped by the scalar
	 * `AssessmentResult.learnerRef` the attempt gate stamps, and exposes no
	 * responses or scores: a result only leaves learniq through the `result`
	 * step, once the teacher released it. The `timedTask` block names the five
	 * endpoint actions of studentTestActions().
	 *
	 * @return array<string, mixed> The studentTests collection.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008
	 */
	public function studentTestsCollection(): array {
		return [
			'id' => 'studentTests',
			'kind' => 'timedTask',
			'register' => self::REGISTER,
			'schema' => 'assessment-result',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'label' => 'My tests',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => [
				'assessmentId',
				'assessmentTitle',
				'lifecycle',
				'attemptNumber',
				'startedAt',
				'submittedAt',
			],
			'timedTask' => [
				'available' => 'listTests',
				'start' => 'startTest',
				'answer' => 'saveTestAnswer',
				'submit' => 'submitTest',
				'result' => 'readTestResult',
			],
		];

	}//end studentTestsCollection()
}//end class
