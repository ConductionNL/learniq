<?php

/**
 * Scholiq Boot Listener Registrar
 *
 * The boot-phase half of the app's listener wiring, delegated to from
 * `Application::boot()`. Every listener here declares the register/schema pair
 * it reacts to up front, so an uninterested object write never constructs it.
 *
 * This MUST run from boot(), never from register(). Nextcloud enables each
 * app's autoloader immediately before calling THAT app's own register(), so
 * from register() the `class_exists()` guard in
 * {@see self::registerFilteredObjectListener()} is boot-order dependent:
 * OpenRegister's classes are only autoloadable to apps that happen to register
 * after it, and every earlier app silently took the unfiltered fallback branch.
 * boot() runs only after every app's register() has completed, so the guard
 * resolves regardless of this app's position.
 *
 * Every listener below is an ADR-031 legitimate exception: a cross-object
 * write no declarative schema expression covers.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo\Registrar
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Scholiq\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCA\Scholiq\Listener\AssessmentDrawResolver;
use OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler;
use OCA\Scholiq\Listener\EngagementSignalHandler;
use OCA\Scholiq\Listener\EnrolmentProgressRollupHandler;
use OCA\Scholiq\Listener\LearnerEngagementRollupHandler;
use OCA\Scholiq\Listener\LessonProgressHandler;
use OCA\Scholiq\Listener\SessionConflictListener;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Subscribes the boot-phase object listeners that declare their register/schema interest.
 */
class BootListenerRegistrar {
	/**
	 * Subscribe every boot-phase filtered object listener.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $appId The Scholiq app id (log context only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function register(IEventDispatcher $dispatcher, string $appId): void {
		// ADR-031 legitimate exception: xAPI completion → Enrolment lifecycle transition.
		// Listens for OR's ObjectCreatedEvent (fires when any OR object is saved); the
		// handler filters to XapiStatement schema objects in the scholiq register.
		// All other Enrolment behaviour is declarative in scholiq_register.json.
		//
		// The register/schema pair the handler's own guard tests is declared up
		// front so an unrelated object write never constructs it.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: XapiCompletionHandler::class,
			registers: ['scholiq'],
			schemas: ['xapi-statement']
		);

		// ADR-031 legitimate exception (competency-framework):
		// WerkprocesAssessment creation -> server-side competencyId resolution
		// bridge. Only the ObjectCreatedEvent half of
		// CompetencyAttainmentRollupHandler is narrowed: handleObjectCreated()
		// guards on register `scholiq` + schema `werkproces-assessment`. Its
		// ObjectTransitionedEvent half stays a plain registration in register().
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: CompetencyAttainmentRollupHandler::class,
			registers: ['scholiq'],
			schemas: ['werkproces-assessment']
		);

		// ADR-031 legitimate exception (learning-progress-and-analytics): xAPI
		// completion statement -> per-lesson LessonCompletion upsert bridge.
		// Listens for the SAME ObjectCreatedEvent<XapiStatement> XapiCompletionHandler
		// already consumes — a sibling listener, NOT an edit to that class. No
		// mandatoryTraining or last-lesson gate: every resolvable completed/passed
		// statement produces or updates a LessonCompletion row.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: LessonProgressHandler::class,
			registers: ['scholiq'],
			schemas: ['xapi-statement']
		);

		// ADR-031 legitimate exception (learning-progress-and-analytics):
		// LessonCompletion creation -> Enrolment.progressPercent recompute bridge.
		// Listens for ObjectCreatedEvent<LessonCompletion>; the DSL has no division
		// operator (verified), mirrors FinalGrade/GradeRollupHandler's shape.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: EnrolmentProgressRollupHandler::class,
			registers: ['scholiq'],
			schemas: ['lesson-completion']
		);

		$this->registerAnalyticsListeners(dispatcher: $dispatcher, appId: $appId);

	}//end register()

	/**
	 * Wire the engagement, item-pool and timetabling listeners that declare the
	 * register/schema pair they react to up front.
	 *
	 * Split out of register() only to keep each registration block readable; the
	 * ordering between the two halves carries no meaning, because every
	 * listener is filtered by its own declared register/schema pair.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher resolved by boot().
	 * @param string $appId The Scholiq app id (log context only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function registerAnalyticsListeners(IEventDispatcher $dispatcher, string $appId): void {
		// ADR-031 legitimate exception (learning-progress-and-analytics): xAPI
		// statement -> EngagementScore recompute + EngagementRiskThreshold check ->
		// EngagementRiskFlag creation bridge. Listens for the SAME
		// ObjectCreatedEvent<XapiStatement> LessonProgressHandler independently
		// reacts to. Mirrors BsaProgressFlagHandler's combined evaluate-then-flag
		// shape. Rule-based only — no AI/ML inference; never auto-acts against the
		// learner.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: EngagementSignalHandler::class,
			registers: ['scholiq'],
			schemas: ['xapi-statement']
		);

		// ADR-031 legitimate exception (assessment-item-pools-and-analysis):
		// AssessmentResult creation -> server-side item-pool draw + shuffle
		// resolution bridge. Listens for OR's ObjectCreatedEvent, filtered to
		// schema `assessment-result`. Resolves and persists drawnItemRefs —
		// never trusts a client-supplied value, mirroring the trust boundary
		// AssessmentScoringHandler already enforces for autoScore. Populated
		// for EVERY attempt (fixed or random-draw) so exam-board review/
		// appeal always has a faithful reconstruction of what the learner saw.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: AssessmentDrawResolver::class,
			registers: ['scholiq'],
			schemas: ['assessment-result']
		);

		// ADR-031 legitimate exception (engagement-gamification): PointAward
		// creation -> LearnerEngagement totals/level/streak recompute bridge,
		// plus the streak-milestone bonus-award check (recursion-guarded on
		// sourceKind). Mirrors GradeRollupHandler's FinalGrade roll-up shape;
		// NOT a TimedJob (ADR-022) and NOT a declarative sum aggregation (no
		// sum metric is precedented anywhere in this register).
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: LearnerEngagementRollupHandler::class,
			registers: ['scholiq'],
			schemas: ['point-award']
		);

		// ADR-031 legitimate exception (timetabling-and-substitution): Session
		// create/update -> TimetableConflictDetector pairwise overlap scan
		// dispatcher. Registered against BOTH ObjectCreatedEvent and
		// ObjectUpdatedEvent (the latter is a real OpenRegister event class
		// with no prior scholiq listener precedent, needed here since a
		// Session's roomId/startsAt/endsAt can be edited via the generic OR
		// object-update endpoint without any lifecycle transition). The
		// actual scan algorithm lives in TimetableConflictDetector, not here
		// — it only ever creates TimetableConflict rows, never edits a
		// Session.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectCreatedEvent::class,
			listener: SessionConflictListener::class,
			registers: ['scholiq'],
			schemas: ['session']
		);
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			appId: $appId,
			event: ObjectUpdatedEvent::class,
			listener: SessionConflictListener::class,
			registers: ['scholiq'],
			schemas: ['session']
		);

	}//end registerAnalyticsListeners()

	/**
	 * Register an object-lifecycle listener that declares its interest up front.
	 *
	 * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
	 * a listener reacts to and routes dispatches through a single shared proxy,
	 * so an uninterested listener is neither constructed nor invoked. Every
	 * listener wired through here already re-derives the same answer inside its
	 * own `handle()` (`getRegister()`/`getSchema()` guard); declaring it at
	 * registration time moves that decision ahead of construction instead of
	 * after it. When OpenRegister is absent — scholiq carries no hard dependency
	 * on it — this degrades to the plain global registration it replaced, which
	 * is exactly the behaviour every listener had before.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $appId The Scholiq app id (log context only).
	 * @param string $event OpenRegister event class name.
	 * @param string $listener Listener class name.
	 * @param array<int,string> $registers Register slugs the listener reacts to.
	 * @param array<int,string> $schemas Schema slugs the listener reacts to.
	 *
	 * @return void
	 */
	private function registerFilteredObjectListener(
		IEventDispatcher $dispatcher,
		string $appId,
		string $event,
		string $listener,
		array $registers,
		array $schemas,
	): void {
		$subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
		if (class_exists($subscription) === true) {
			$subscription::subscribe(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
			return;
		}

		// Loud on purpose. This fallback is correct but UNFILTERED, and while it
		// was silent it was indistinguishable from a working narrowing.
		\OCP\Server::get('Psr\\Log\\LoggerInterface')->warning(
			'OpenRegister ObjectEventSubscription unavailable: ' . $listener
			. ' fell back to an UNFILTERED registration for ' . $event
			. ' and will be invoked on every object write instance-wide.',
			['app' => $appId]
		);

		$dispatcher->addServiceListener($event, $listener);

	}//end registerFilteredObjectListener()
}//end class
