<?php

/**
 * Learniq Learning Record Import Parse Action
 *
 * Transition action for the LearningRecordImport schema's `parse` transition.
 * Parses the uploaded bundle and returns the import with `entries`,
 * `issuerDid` and `verificationStatus` set. Throws when the bundle can not be
 * read, decoded or recognised, which aborts the transition, so an import is
 * never saved as parsed with partial data.
 *
 * The write used to happen inside the guard, but OpenRegister calls a guard by
 * value and only merges back what an action returns (learniq#983).
 *
 * Referenced from the LearningRecordImport schema's
 * x-openregister-lifecycle.transitions.parse.actions in learniq_register.json.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\LearningRecordImportService;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

/**
 * Writes the parsed coverage report onto a parsed LearningRecordImport.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
 */
class LearningRecordImportParseAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param LearningRecordImportService $importService The bundle parser.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearningRecordImportService $importService,
	) {
	}//end __construct()

	/**
	 * Return the import with its parsed coverage report recorded.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the import after the lifecycle moved to
	 *                                        `parsed`.
	 *
	 * @return array<string,mixed> The import to save.
	 *
	 * @throws \RuntimeException When the bundle can not be read, decoded or recognised.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-coordinator-can-upload-another-institution-s-record-as-evidence-during-application-intake
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->importService->parse(import: $objectData);
	}//end execute()
}//end class
