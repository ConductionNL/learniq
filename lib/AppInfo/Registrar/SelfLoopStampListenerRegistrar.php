<?php

/**
 * Learniq Self-Loop Stamp Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to. This one wires the ObjectTransitionedEvent
 * listeners that write what a self-loop transition's guard used to write into
 * the transition payload (learniq#983). The existing registrars sit at the
 * phpmd coupling limit, so this is its own class.
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-persist-dataexchangejob-and-datamappingprofile-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\MunicipalityFeedbackStampListener;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the self-loop stamp listeners.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-persist-dataexchangejob-and-datamappingprofile-in-openregister
 */
class SelfLoopStampListenerRegistrar {
	/**
	 * Register every self-loop stamp listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-persist-dataexchangejob-and-datamappingprofile-in-openregister
	 */
	public function register(IRegistrationContext $context): void {
		// DataExchangeJob.recordMunicipalityFeedback is succeeded -> succeeded.
		// OpenRegister runs neither guards nor actions on a self-loop, but
		// TransitionEngine dispatches ObjectTransitionedEvent after every save,
		// so recordedBy/receivedAt are stamped here.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: MunicipalityFeedbackStampListener::class
		);
	}//end register()
}//end class
