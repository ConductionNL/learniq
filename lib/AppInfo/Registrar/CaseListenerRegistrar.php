<?php

/**
 * Learniq Case Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to, so no single class has to name every listener in
 * the app. This one wires the data-exchange and case-handling bridges: the
 * listeners that run or answer a DataExchangeJob, and the ones that react to a
 * support request, a rollover, a BPV placement, a fraud case or a study-progress
 * signal.
 *
 * Every listener below is an ADR-031 legitimate exception: a cross-object
 * write no declarative schema expression covers.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Learniq\Lifecycle\RolloverExecutionHandler;
use OCA\Learniq\Listener\BpvLeerbedrijfVerificationHandler;
use OCA\Learniq\Listener\BsaProgressFlagHandler;
use OCA\Learniq\Listener\CompetencyAttainmentRollupHandler;
use OCA\Learniq\Listener\ExchangeGateListener;
use OCA\Learniq\Listener\ExchangeJobConcludedListener;
use OCA\Learniq\Listener\FraudCaseDecisionHandler;
use OCA\Learniq\Listener\LearnerMergeHandler;
use OCA\Learniq\Listener\SupportRequestSubmitHandler;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the data-exchange, case-handling and study-progress bridges.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */
class CaseListenerRegistrar {
	/**
	 * Register every data-exchange/case-handling listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerDataExchangeListeners(context: $context);
		$this->registerCaseAndProgressListeners(context: $context);

	}//end register()

	/**
	 * Register the data exchange listeners (data-exchange-to-integriq).
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function registerDataExchangeListeners(IRegistrationContext $context): void {
		// ADR-041: integriq asks learniq's gate before an exchange job owned by
		// learniq runs, and tells learniq when the job ends. Registered by class
		// name, so without integriq nothing is ever dispatched to them.
		$context->registerEventListener(
			event: ExchangeGateListener::GATE_EVENT,
			listener: ExchangeGateListener::class
		);
		$context->registerEventListener(
			event: ExchangeJobConcludedListener::CONCLUDED_EVENT,
			listener: ExchangeJobConcludedListener::class
		);

		// ADR-031 legitimate exception: SupportRequest `submit` -> ask integriq for
		// the SWV exchange job and open the parents' DossierReview.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: SupportRequestSubmitHandler::class
		);

	}//end registerDataExchangeListeners()

	/**
	 * Register the rollover, BPV, fraud-case and study-progress listeners.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	private function registerCaseAndProgressListeners(IRegistrationContext $context): void {
		// ADR-031 legitimate exception: RolloverPlan `previewed → executing`
		// (and `failed → executing` retry) → run the chunked, idempotent
		// jaarovergang via RolloverService, then drive the plan to
		// completed/failed. Event-driven (NOT IRegistrationContext::registerJob,
		// per the fleet jobs-never-ran bug); execution is resumable so a failed
		// plan retries without duplicating created cohorts or carried enrolments.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: RolloverExecutionHandler::class
		);

		// ADR-031 legitimate exception: LearnerProfile `merge` (active -> merged)
		// -> move the merged account's enrolments, grades, attendance,
		// credentials, portfolio entries and other learner-owned records to the
		// surviving profile named in mergedInto (learniq#950). LearnerMergeGuard
		// validated the target before the transition ran.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: LearnerMergeHandler::class
		);

		// ADR-031 legitimate exception: BpvPlacement `checkLeerbedrijf` self-transition
		// (→ sbb-verification-pending) → resolve the configured
		// ProvidesLeerbedrijfVerification adapter (if any) and write the SBB
		// erkend-leerbedrijf verification result back onto the placement.
		// No provider configured is a no-op — Learniq ships no bundled SBB adapter.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: BpvLeerbedrijfVerificationHandler::class
		);

		// ADR-031 legitimate exception: FraudCase `decided` (verdict: fraud-proven)
		// → contested GradeEntry.invalidate bridge. Only acts on a still-concept
		// linked GradeEntry; a published entry is left untouched (defensive —
		// FraudCaseBlockGuard should have prevented that state), per
		// exam-board-case-handling design §4.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: FraudCaseDecisionHandler::class
		);

		// ADR-031 legitimate exception: GradeEntry.published → BsaTrajectory
		// at-risk check → BsaProgressFlag creation bridge (bsa-study-progress-guard).
		// Listens for the same event GradeRollupHandler reacts to (a learner's
		// earned credits can only change when a GradeEntry publishes). Resolves
		// the Programme(s) the published Course belongs to, evaluates every
		// active BsaTrajectory in scope via BsaProgressEvaluator, and creates a
		// BsaProgressFlag (open) when the learner falls below interimNormEcts
		// once the interim-check window has opened. NOT a TimedJob (ADR-022);
		// never auto-acts against the learner.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: BsaProgressFlagHandler::class
		);

		// ADR-031 legitimate exception: WerkprocesAssessment creation ->
		// server-side competencyId resolution bridge, and GradeEntry.published /
		// WerkprocesAssessment.confirmed -> CompetencyAttainment roll-up bridge
		// (competency-framework). One class, registered against both OR event
		// classes — handle() branches on instanceof. Mirrors GradeRollupHandler/
		// WerkprocesGradeEmitHandler's cross-schema write-bridge shape; never a
		// TimedJob (ADR-022).
		//
		// Only the ObjectCreatedEvent half is narrowed, and it is subscribed
		// from boot() (see BootListenerRegistrar). The ObjectTransitionedEvent
		// half stays a plain registration — it already only fires on a lifecycle
		// transition, and it reads the event's own getRegister()/getSchema(),
		// not the written object's.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CompetencyAttainmentRollupHandler::class
		);

	}//end registerCaseAndProgressListeners()
}//end class
