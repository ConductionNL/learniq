<?php

/**
 * Learniq Governance Listener Registrar
 *
 * Registers the listeners of the four-eyes rule on approved data: the
 * correction request stamp (a pre-write rule, registered directly like the
 * other owner stamps) and the handler that marks a correction applied once
 * its grade is published again.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\CorrectionAppliedHandler;
use OCA\Learniq\Listener\DataCorrectionRequestStamp;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the correction request stamp and the correction applied handler.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class GovernanceListenerRegistrar {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: DataCorrectionRequestStamp::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: DataCorrectionRequestStamp::class
		);

		// ADR-031 exception: GradeEntry published on an approved correction ->
		// the correction is applied and the grade names it (two writes on two objects).
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CorrectionAppliedHandler::class
		);
	}//end register()
}//end class
