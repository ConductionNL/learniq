<?php

/**
 * Learniq Attempt Limit Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to. This one wires the time limit of a learner's
 * attempt (in-app-test-limits-server-side): the learner cannot move an
 * attempt's start or number, and after the deadline plus the grace its
 * answers stop changing, as on the portal.
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
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\AssessmentAttemptTimeLimitListener;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the attempt time limit.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
 */
class AttemptLimitListenerRegistrar {
	/**
	 * Register the attempt time limit listener.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-the-in-app-test-screen-enforces-attempts-and-time-on-the-server
	 */
	public function register(IRegistrationContext $context): void {
		// A pre-write rule on the updating event. Registered directly, not
		// narrowed through ObjectEventSubscription, for the reason the
		// integrity rules are: its shared proxy does not consult
		// isPropagationStopped() between subscriptions.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: AssessmentAttemptTimeLimitListener::class
		);
	}//end register()
}//end class
