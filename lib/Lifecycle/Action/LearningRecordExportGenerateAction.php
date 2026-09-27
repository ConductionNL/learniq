<?php

/**
 * Learniq Learning Record Export Generate Action
 *
 * Transition action for the LearningRecordExport schema's `generate`
 * transition. Assembles, signs and stores the export bundle and returns the
 * export with `coverageReport`, `bundleRef`, `bundleSignature`, `issuerDid`
 * and `generatedAt` set. Throws when any step fails, which aborts the
 * transition, so an export is never saved as generated without its bundle.
 *
 * The write used to happen inside the guard, but OpenRegister calls a guard by
 * value and only merges back what an action returns (learniq#983).
 *
 * Referenced from the LearningRecordExport schema's
 * x-openregister-lifecycle.transitions.generate.actions in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\LearningRecordExportService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

/**
 * Writes the signed bundle onto a generated LearningRecordExport.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */
class LearningRecordExportGenerateAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param LearningRecordExportService $exportService The bundle assembler.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearningRecordExportService $exportService,
	) {
	}//end __construct()

	/**
	 * Return the export with its signed bundle recorded.
	 *
	 * @param array<string,mixed> $objectData The export after the lifecycle moved to `generated`.
	 * @param array<string,mixed> $previousData The export before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (unused).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The export to save.
	 *
	 * @throws \RuntimeException When the bundle can not be composed, signed or stored.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->exportService->generate(export: $objectData);
	}//end execute()
}//end class
