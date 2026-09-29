<?php

/**
 * Learniq Elective Listener Registrar
 *
 * Wires the rules of an optional lesson sign-up
 * (timetabling-elective-lesson-signup): eligibility, the window, capacity and
 * one sign-up per learner per lesson, on every create and update.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\ElectiveSignUpRules;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the sign-up rules.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */
class ElectiveListenerRegistrar {
	/**
	 * Register the sign-up rules on the creating and updating events.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function register(IRegistrationContext $context): void {
		// Pre-write vetoes, registered directly and not narrowed through
		// ObjectEventSubscription: its shared proxy does not consult
		// isPropagationStopped() between subscriptions.
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: ElectiveSignUpRules::class);
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: ElectiveSignUpRules::class);

		// The exam schedule checks (timetabling-exam-schedule): pre-write, like the
		// sign-up rules above, in a registrar of their own.
		(new ExamScheduleListenerRegistrar())->register(context: $context);
	}//end register()
}//end class
