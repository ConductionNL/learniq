<?php

/**
 * Learniq Evidence Freeze Listener Registrar
 *
 * Called from IntegrityListenerRegistrar. Wires the pre-write vetoes that keep
 * finished evidence fixed on schemas that dropped `appendOnly` so their
 * lifecycles could run: a submitted AssessmentResult (learniq#948) and a
 * verified LvsResult (lvs-score-freeze).
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\AssessmentResultIntegrityListener;
use OCA\Learniq\Listener\LvsResultFreezeListener;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the evidence freezes.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
 */
class EvidenceFreezeListenerRegistrar {
	/**
	 * Register the evidence freezes.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
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

		// LvsResult freeze (lvs-score-freeze): replaces `appendOnly`, which
		// refused `verify` and `archive` too (learniq#1124). Once verified,
		// the score fields are fixed. A pre-write veto, registered directly
		// for the same reason.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: LvsResultFreezeListener::class
		);
	}//end register()
}//end class
