<?php

/**
 * Learniq LinkCoveringCorrectionAction
 *
 * Transition action on GradeEntry.republish: when an approved correction
 * request covers the republish (same entry, the approved value, approved by
 * someone other than the requester and the publisher), the entry names it in
 * `correctionRequestId`, in the same save as the move to published.
 *
 * `correctionRequestId` is readOnly ("Filled in by the app"), and OpenRegister
 * refuses an update that changes a readOnly property whoever saves. A
 * declared action is how the register fills one: OpenRegister runs it on the
 * save path after the readOnly check. CorrectionAppliedHandler used to write
 * the link in a second save after the publish, which the live instance would
 * refuse the same way it refused appliedBy/appliedAt (live pass D10).
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names the approved correction that covers a republish on the grade entry.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class LinkCoveringCorrectionAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param CorrectionApprovals $approvals   The approved correction for a grade entry.
	 * @param IUserSession        $userSession The session whose user publishes.
	 * @param LoggerInterface     $logger      PSR logger.
	 */
	public function __construct(
		private readonly CorrectionApprovals $approvals,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set `correctionRequestId` when an approved correction covers this publish.
	 *
	 * A publish without a covering correction, without a session user, or
	 * whose lookup fails keeps the entry as it is: the link is a record of the
	 * correction, never a reason to refuse the publish (the period lock guard
	 * decides that).
	 *
	 * @param array<string,mixed> $objectData   The entry after the lifecycle field moved to published.
	 * @param array<string,mixed> $previousData The entry before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters` (none).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The entry, with the link when a correction covers it.
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$publisher = (string)($this->userSession->getUser()?->getUID() ?? '');

		try {
			$request = $this->approvals->approvedFor(entry: $objectData, publisher: $publisher);
		} catch (Throwable $e) {
			$this->logger->error(
				'[LinkCoveringCorrectionAction] Could not look up the correction for grade entry {entry}: {error}',
				['entry' => $objectData['id'] ?? '', 'error' => $e->getMessage()]
			);
			return $objectData;
		}

		$requestId = (string)($request['id'] ?? ($request['uuid'] ?? ''));
		if ($requestId === '') {
			return $objectData;
		}

		$objectData['correctionRequestId'] = $requestId;
		return $objectData;
	}//end execute()
}//end class
