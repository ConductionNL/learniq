<?php

/**
 * Learniq Scheduling Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to, so no single class has to name every listener in
 * the app. This one wires the intake-to-enrolment path (prerequisites,
 * admissions, subject choice), plus payments, session-change notices and the
 * optional openconnector wallet-claim listener.
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

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Learniq\Listener\AdmissionsWaitlistPromoter;
use OCA\Learniq\Listener\ApplicationConversionHandler;
use OCA\Learniq\Listener\EnrolmentPrerequisiteListener;
use OCA\Learniq\Listener\PaymentTransactionStatusHandler;
use OCA\Learniq\Listener\SessionChangeNoticeHandler;
use OCA\Learniq\Listener\SubjectChoiceEnrolmentBridge;
use OCA\Learniq\Listener\SubjectChoiceValidator;
use OCA\Learniq\Listener\WalletOfferConcludedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the admissions, subject-choice, payment and session-change bridges.
 */
class SchedulingListenerRegistrar {
	/**
	 * Register every intake/enrolment/payment/session listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function register(IRegistrationContext $context): void {
		// ADR-031 legitimate exception (adaptive-release-and-prerequisites):
		// Enrolment prerequisite gate. Listens for OR's ObjectCreatingEvent on
		// the enrolment schema and vetoes the create (stopPropagation) when the
		// target Course's prerequisiteCourseIds are not all satisfied by a
		// completed Enrolment the learner already holds. A requires-style
		// x-openregister-lifecycle guard CANNOT enforce this — Enrolment has no
		// transition into its initial `pending` state — so this is a raw
		// creation-time hook, mirroring decidesk's SubmissionDeadlineListener /
		// larpingapp's CharacterRequirementListener.
		//
		// Deliberately NOT narrowed through ObjectEventSubscription: this is a
		// pre-write veto listener. That mechanism's shared proxy documents
		// itself as safe for `*ed` post-events only — it reorders subscriptions
		// into a single dispatcher slot and does not consult
		// isPropagationStopped() between them, which is precisely the signal
		// this listener uses to block the create.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: EnrolmentPrerequisiteListener::class
		);

		// ADR-031 legitimate exception (admissions-and-subject-choice):
		// Application `withdrawn`/`rejected` FROM `placed` -> oldest-submittedAt
		// waitlisted Application promotion bridge. Mirrors
		// ConferenceScheduleGenerator's freed-resource-promotes-oldest shape;
		// re-runs the normal `promote` transition (and therefore
		// AdmissionsDecisionGuard's capacity branch) rather than writing the
		// lifecycle field directly.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: AdmissionsWaitlistPromoter::class
		);

		// ADR-031 legitimate exception (admissions-and-subject-choice):
		// Application `placed` -> LearnerProfile + Enrolment (source:
		// admission) creation bridge, then drives the Application through its
		// existing `convert` transition. NC user-account/LMS provisioning is
		// explicitly NOT part of this bridge (design.md Non-Goals).
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: ApplicationConversionHandler::class
		);

		// ADR-031 legitimate exception (admissions-and-subject-choice):
		// SubjectChoice `submitted` -> electiveRules/capacity validation
		// bridge, writing `validated`/`needs-revision` (+ validationErrors[])
		// directly, mirroring ConferenceScheduleGenerator's
		// route-to-one-of-two-states shape rather than a `requires` guard.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: SubjectChoiceValidator::class
		);

		// ADR-031 legitimate exception (admissions-and-subject-choice):
		// SubjectChoice `approved -> locked` -> Enrolment (source:
		// subject-choice) creation bridge, one per selected elective course
		// not already enrolled. Mirrors ExcuseApprovalHandler's cross-schema
		// write-bridge shape.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: SubjectChoiceEnrolmentBridge::class
		);

		// ADR-031 legitimate exception (school-payments): PaymentTransaction
		// `succeeded`/`refunded` -> Order paid/partially-paid roll-up and
		// refund cascade (revoking any active Entitlements reachable through
		// the Order's OrderLines). Event-driven, NOT a TimedJob (ADR-022).
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: PaymentTransactionStatusHandler::class
		);

		// ADR-031 legitimate exception (timetabling-and-substitution): Session
		// `cancel`/`substitute-teacher`/`substitute-teacher-in-progress` ->
		// affectedLearnerIds/affectedParentIds/changedAt materialisation
		// bridge, mirroring ConferenceRound.invitedLearnerIds's cross-schema
		// two-hop join shape (Cohort.learnerIds -> each LearnerProfile.
		// parentIds), so the declared x-openregister-notifications rules'
		// kind:field recipients can resolve without a runtime join.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: SessionChangeNoticeHandler::class
		);

		$this->registerWalletOfferConcludedListener(context: $context);

	}//end register()

	/**
	 * Register the openconnector wallet-claim listener.
	 *
	 * Learniq delegates EUDI-wallet offer creation/revocation to
	 * openconnector's `eudi-wallet-credential-issuance` REST adapter
	 * ({@see \OCA\Learniq\Service\WalletOfferDelegationService}). This
	 * listener would consume the terminal "wallet holder claimed the offer"
	 * signal, but as documented on
	 * {@see \OCA\Learniq\Listener\WalletOfferConcludedListener}'s docblock,
	 * openconnector's merged adapter defines no such event — the
	 * `class_exists` guard below evaluates false today and this
	 * registration is a no-op. Kept `class_exists`-guarded by FQN string
	 * (not `::class`) so learniq carries no hard compile-time dependency on
	 * the optional openconnector app, mirroring
	 * `procest\AppInfo\Application::registerDecisionListeners()`.
	 *
	 * 🔴 DO NOT "FIX" THE NAMESPACE HERE. The connector app renamed its PSR-4
	 * root from `OCA\OpenConnector` to `OCA\Integriq`, and across the fleet
	 * that rename really did take cross-app bindings dark: the lookup answers
	 * false instead of erroring, so the integration just stops. This site looks
	 * exactly like one of those and is not one.
	 *
	 * Verified 2026-09-09 against integriq `development` a5e43d8. `lib/Event/`
	 * holds DeliveryConcludedEvent, DeliveryRequestedEvent and
	 * SynchronizationDeletionGuardedEvent, and the string "WalletOffer" does
	 * not occur anywhere in that repository. The class exists under NEITHER
	 * name — not `OCA\Integriq\Event\WalletOfferConcludedEvent` and not the
	 * one below. Repointing would change the diff and change nothing else,
	 * while reading to the next person as a fix that had been applied.
	 *
	 * Adding the current spelling alongside the old one was considered and
	 * rejected for the same reason: a two-entry list is a claim that one of
	 * them resolves somewhere, and neither does. What is missing is upstream —
	 * the connector app dispatches no wallet-claim signal at all
	 * ({@see \OCA\Learniq\Listener\WalletOfferConcludedListener}) — so this
	 * guard is correct as written until that signal ships, and it is the SIGNAL
	 * this waits on, not a name.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
	 */
	private function registerWalletOfferConcludedListener(IRegistrationContext $context): void {
		// @stale-fleet-app-id exclude the class exists under NEITHER name. Re-verified
		// 2026-09-10: `git log -S WalletOfferConcludedEvent` over integriq's full
		// 3,960-commit history, which spans the whole openconnector era, returns
		// nothing, and the string does not occur anywhere in the repo today. What is
		// missing is the upstream signal, not the name. See the docblock above.
		if (class_exists('\\OCA\\OpenConnector\\Event\\WalletOfferConcludedEvent') === false) {
			return;
		}

		// @stale-fleet-app-id exclude same binding as the guard above, and the same
		// evidence: integriq dispatches no wallet-claim event under either spelling,
		// confirmed 2026-09-10 by git log -S over its full history. A dual-spelling
		// list was rejected on purpose, because it would claim one of the two
		// resolves somewhere and neither does.
		$context->registerEventListener(
			event: 'OCA\OpenConnector\Event\WalletOfferConcludedEvent',
			listener: WalletOfferConcludedListener::class
		);

	}//end registerWalletOfferConcludedListener()
}//end class
