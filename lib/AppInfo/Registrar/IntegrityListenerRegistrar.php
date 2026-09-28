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

use OCA\Learniq\Listener\AssessmentAttemptTimeLimitListener;
use OCA\Learniq\Listener\AssessmentResultIntegrityListener;
use OCA\Learniq\Listener\CompetencyAlignmentListener;
use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Listener\PortfolioEntryOwnershipListener;
use OCA\Learniq\Listener\SubmissionLearnerRefsStamp;
use OCA\Learniq\Listener\SubmissionOwnerStamp;
use OCA\Learniq\Listener\SubmissionResubmissionDateListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the evidence-integrity vetoes.
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
		// AssessmentResult integrity (learniq#948): replaces `appendOnly`, which
		// refused every lifecycle write. Freezes a submitted attempt's answers,
		// lets only staff write manualScore and fire `grade`, and refuses
		// deletes. A pre-write veto, so deliberately NOT narrowed through
		// ObjectEventSubscription: its shared proxy does not consult
		// isPropagationStopped() between subscriptions.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: AssessmentResultIntegrityListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletingEvent::class,
			listener: AssessmentResultIntegrityListener::class
		);

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

		$this->registerAttemptTimeLimit(context: $context);
	}//end register()

	/**
	 * Attempt time limit (in-app-test-limits-server-side): the learner cannot
	 * move an attempt's start or number, and after the deadline plus the grace
	 * its answers stop changing, as on the portal. A pre-write rule on the
	 * updating event, registered directly like the integrity rules above.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/in-app-test-limits-server-side/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	private function registerAttemptTimeLimit(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: AssessmentAttemptTimeLimitListener::class
		);
	}//end registerAttemptTimeLimit()
}//end class
