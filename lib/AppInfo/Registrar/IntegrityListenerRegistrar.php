<?php

/**
 * Learniq Integrity Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to. This one wires the pre-write vetoes that keep
 * evidence objects immutable where a schema flag cannot: today the
 * AssessmentResult integrity rules that replace `appendOnly` (learniq#948).
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\CompetencyAlignmentListener;
use OCA\Learniq\Listener\ConferenceSignupPortalStamp;
use OCA\Learniq\Listener\ExcuseRequestOwnerStamp;
use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Listener\LessonNoteAuthorGuard;
use OCA\Learniq\Listener\PortfolioEntryOwnershipListener;
use OCA\Learniq\Listener\SubmissionLearnerRefsStamp;
use OCA\Learniq\Listener\SubmissionOwnerStamp;
use OCA\Learniq\Listener\SubmissionResubmissionDateListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the evidence-integrity vetoes.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) This class exists to name
 * every integrity listener in one place, the same reason BootListenerRegistrar
 * carries this suppression. Each listener is one more class by construction;
 * splitting the registrar to dodge the metric would move the same coupling
 * around without reducing it.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
 */
class IntegrityListenerRegistrar {
	/**
	 * Register every integrity listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-teacher-scores-open-answers-question-by-question-and-a-finished-attempt-stays-immutable
	 */
	public function register(IRegistrationContext $context): void {
		// The evidence freezes (a submitted AssessmentResult, a verified
		// LvsResult) live in their own registrar.
		(new EvidenceFreezeListenerRegistrar())->register(context: $context);

		$this->registerOwnerStamps(context: $context);

		// PortfolioEntry ownership (learniq#981): every learner may create an
		// entry, so a non-staff caller may only write one in their own name
		// into their own portfolio.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: PortfolioEntryOwnershipListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: PortfolioEntryOwnershipListener::class
		);

		// Lesson notes (timetabling-lesson-note): only a lesson's own teachers,
		// its substitute and team leads write a note on it. A rule across
		// rows (cohort teachers, substitute), so a pre-write veto.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: LessonNoteAuthorGuard::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: LessonNoteAuthorGuard::class
		);

		// Competency alignments (goal-alignment-depth): keeps competencyIds
		// derived from competencyAlignments on Lesson, Course, Assignment and
		// Assessment, and refuses a depth the goal's framework does not know.
		// A pre-write veto that also writes, so registered directly.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: CompetencyAlignmentListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: CompetencyAlignmentListener::class
		);

		// GradeEntry learnerRef (gradeentry-learnerref-stamp): the server
		// derives the portal subject from learnerId on every write, whoever
		// creates the grade. A stamp, not a veto: it never stops the write.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: GradeEntryLearnerRefStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: GradeEntryLearnerRefStamp::class
		);

		// Submission learnerRefs (learner-lookup-and-learnerrefs-fixes): the
		// portal's student submissions collection scopes on learnerRefs, so the
		// server derives it from learnerIds on every write. A stamp, not a veto.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: SubmissionLearnerRefsStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: SubmissionLearnerRefsStamp::class
		);

		// Submission resubmission date (submission-resubmission-action): the
		// date moves the hand-in deadline, so only staff may write it. Drops
		// or restores the value; never stops the write.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: SubmissionResubmissionDateListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: SubmissionResubmissionDateListener::class
		);
	}//end register()

	/**
	 * Register the owner stamps: the listeners that fill, on the server, who a
	 * portal write belongs to, and refuse any write that still lacks it.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 */
	private function registerOwnerStamps(IRegistrationContext $context): void {
		// Submission owner (assignment-portal-wiring): a portal hand-in gets
		// its learners and tenant from the pupil's profile, every other write
		// gets learnerRef from learnerIds[0], and no write may end without
		// learners or tenant. Create and update, so a client never keeps a
		// learnerRef of its own.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: SubmissionOwnerStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: SubmissionOwnerStamp::class
		);

		// ExcuseRequest owner (settings-and-excuse-authorization): a portal
		// absence report gets its pupil, submitter, level and tenant from the
		// profile, a guardian's only for a child that lists them, every other
		// write gets learnerRef from learnerId, and no write may end without
		// the pupil, a submitter or the tenant.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: ExcuseRequestOwnerStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: ExcuseRequestOwnerStamp::class
		);

		// ConferenceSignup from the parent portal (portal-parent-conference-
		// booking): the child must list the guardian and the round must be
		// open to the child; learnerId, guardianId, tenant and `submitted` are
		// stamped so the scheduling generator considers the signup.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: ConferenceSignupPortalStamp::class
		);
	}//end registerOwnerStamps()
}//end class
