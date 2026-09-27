<?php

/**
 * Learniq Report Card Finalise Guard
 *
 * Lifecycle guard for the ReportCard schema's `finalise` transition
 * (rapportvergadering-review -> finalised). Blocks finalisation until the
 * mentor has recorded an overall comment and the card carries at least one
 * subject-grade row — a report card with no comment and no subjects reaching
 * `finalised` would be a meaningless, empty document handed to parents.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema
 * declaration." Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.finalise.requires in
 * learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the ReportCard `finalise` (rapportvergadering-review -> finalised)
 * lifecycle transition.
 *
 * Allows the transition only when `mentorComment` is a non-empty string and
 * `subjectGrades` is a non-empty array.
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
 */
class ReportCardFinaliseGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'A report card needs a mentor comment and subject grades before it can be finalised.';
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when the card carries a mentor comment and at least one subject grade; false blocks it.
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-finalise-is-blocked-without-a-mentor-comment
	 */
	private function allows(array $object): bool {
		$objectId = $object['id'] ?? ($object['uuid'] ?? '');
		$comment = $object['mentorComment'] ?? null;
		$subjects = $object['subjectGrades'] ?? [];

		if (is_string($comment) === false || trim($comment) === '') {
			$this->logger->info(
				'[ReportCardFinaliseGuard] ReportCard {id} has no mentorComment — denying finalise.',
				['id' => $objectId]
			);
			return false;
		}

		if (is_array($subjects) === false || empty($subjects) === true) {
			$this->logger->info(
				'[ReportCardFinaliseGuard] ReportCard {id} has no subjectGrades — denying finalise.',
				['id' => $objectId]
			);
			return false;
		}

		return true;
	}//end allows()
}//end class
