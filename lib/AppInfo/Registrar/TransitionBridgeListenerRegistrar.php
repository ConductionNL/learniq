<?php

/**
 * Learniq Transition Bridge Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to. This one wires the bridges that answer a lifecycle
 * transition by creating a follow-up object: a renewal Enrolment when a
 * Credential expires, and a bron-rod DataExchangeJob when a SchoolAdvies is
 * sent to ROD. They live here, not in the scheduling and case registrars they
 * were first added to, so that neither of those grows past the size and
 * coupling limits (round1-landing-repairs, from learniq PR 1006).
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
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 * @spec openspec/changes/po-schooladvies-flow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\CredentialRenewalListener;
use OCA\Learniq\Listener\SchoolAdviesSendToRodHandler;
use OCA\Learniq\Listener\SchoolAdviesVoorlopigRodHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the transition bridges that create a follow-up object.
 *
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 * @spec openspec/changes/po-schooladvies-flow/tasks.md
 */
class TransitionBridgeListenerRegistrar {

	/**
	 * Register the transition bridge listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
	 * @spec openspec/changes/po-schooladvies-flow/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		// ADR-031 legitimate exception (credential-renewal-listener):
		// Credential `expire` -> renewal Enrolment create + link-back bridge,
		// closing the expiry half of the pre-existing "Auto-enrol on renewal
		// or content-version change" certification requirement. Mirrors
		// ExemptionGrantHandler's cross-object-create shape.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CredentialRenewalListener::class
		);

		// ADR-031 legitimate exception (po-schooladvies-flow): SchoolAdvies
		// `verzendenNaarRod` transition → ask integriq for the bron-rod exchange
		// job (data-exchange-to-integriq) and stamp its id back onto the
		// SchoolAdvies.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: SchoolAdviesSendToRodHandler::class
		);

		// Schooladvies-voorlopig-to-rod: DUO wants the voorlopig advice within
		// 14 days of giving it, so it goes to ROD once its level and date are
		// saved; deferred out of the save (gate 61).
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: SchoolAdviesVoorlopigRodHandler::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: SchoolAdviesVoorlopigRodHandler::class
		);

	}//end register()
}//end class
