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

use OCA\Learniq\Listener\AssessmentResultIntegrityListener;
use OCA\Learniq\Listener\CompetencyAlignmentListener;
use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Listener\PortfolioEntryOwnershipListener;
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
	}//end register()
}//end class
