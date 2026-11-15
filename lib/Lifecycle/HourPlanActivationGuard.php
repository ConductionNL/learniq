<?php

/**
 * Learniq Hour Plan Activation Guard
 *
 * A programme has at most one active hour plan per intake year: the activity
 * list reads "the" active plan of a programme and intake, so a second one
 * would make the list ambiguous. The `activate` transition is refused while
 * another plan of the same programme and intake year is active, with a reason
 * that names that plan (ADR-031 lifecycle guard exception: a uniqueness rule
 * across rows).
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
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a second active hour plan for the same programme and intake year.
 *
 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
 */
class HourPlanActivationGuard implements LifecycleGuardInterface {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'hour-plan';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the other plans of the programme.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow `activate` only when no other plan of the programme and intake is active.
	 *
	 * @param array<string,mixed> $object The hour plan being activated.
	 * @param string              $action The transition name.
	 * @param string              $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-a-programme-has-an-hour-plan-over-its-whole-length
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$refusal = ($this->incompleteRefusal(plan: $object) ?? $this->siblingRefusal(plan: $object, userId: $userId));

		return ($refusal ?? GuardResult::allow());
	}//end check()

	/**
	 * The refusal of a plan without a programme or an intake year, or null.
	 *
	 * @param array<string,mixed> $plan The hour plan being activated.
	 *
	 * @return GuardResult|null
	 */
	private function incompleteRefusal(array $plan): ?GuardResult {
		if ((string)($plan['programmeId'] ?? '') === '' || (string)($plan['intakeYear'] ?? '') === '') {
			return GuardResult::deny('An hour plan needs a programme and an intake year before it can be activated.');
		}

		return null;
	}//end incompleteRefusal()

	/**
	 * The refusal while another plan of the programme and intake is active, or
	 * when the other plans could not be read; null when none is active.
	 *
	 * @param array<string,mixed> $plan   The hour plan being activated.
	 * @param string              $userId The acting user.
	 *
	 * @return GuardResult|null
	 */
	private function siblingRefusal(array $plan, string $userId): ?GuardResult {
		$rows = $this->intakePlans(plan: $plan);
		if ($rows === null) {
			return GuardResult::deny('The other hour plans of this programme could not be checked. Try again.');
		}

		$active = $this->activeSibling(rows: $rows, plan: $plan);
		if ($active === null) {
			return null;
		}

		$this->logger->info(
			'[HourPlanActivationGuard] {user} tried a second active plan for {p} {y}.',
			['user' => $userId, 'p' => (string)($plan['programmeId'] ?? ''), 'y' => (string)($plan['intakeYear'] ?? '')]
		);
		return GuardResult::deny(sprintf('The hour plan "%s" is already active for this programme and intake year. Archive it first.', $active));
	}//end siblingRefusal()

	/**
	 * The plans of the plan's programme and intake, or null when they could not be read.
	 *
	 * @param array<string,mixed> $plan The hour plan being activated.
	 *
	 * @return array<int,mixed>|null
	 */
	private function intakePlans(array $plan): ?array {
		try {
			return $this->objectService->findAll($this->intakeQuery(plan: $plan), _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->warning('[HourPlanActivationGuard] Could not read hour plans: {msg}', ['msg' => $exception->getMessage()]);
			return null;
		}
	}//end intakePlans()

	/**
	 * The query for every plan of the plan's programme and intake year.
	 *
	 * @param array<string,mixed> $plan The hour plan being activated.
	 *
	 * @return array<string,mixed>
	 */
	private function intakeQuery(array $plan): array {
		return [
			'filters' => [
				'register' => self::REGISTER,
				'schema' => self::SCHEMA,
				'programmeId' => (string)($plan['programmeId'] ?? ''),
				'intakeYear' => (string)($plan['intakeYear'] ?? ''),
			],
		];
	}//end intakeQuery()

	/**
	 * The name of another active plan of the same programme and intake, or null.
	 *
	 * @param array<int,mixed>    $rows The plans of the programme and intake.
	 * @param array<string,mixed> $plan The plan being activated.
	 *
	 * @return string|null The active plan's name (or id), or null when there is none.
	 */
	private function activeSibling(array $rows, array $plan): ?string {
		foreach ($rows as $row) {
			$candidate = $this->toArray(row: $row);
			if ($this->isOtherActivePlan(candidate: $candidate, plan: $plan) === true) {
				return (string)($candidate['name'] ?? $this->planId(plan: $candidate));
			}
		}

		return null;
	}//end activeSibling()

	/**
	 * Whether a stored plan is another active plan of the same programme and intake.
	 *
	 * @param array<string,mixed> $candidate A stored plan.
	 * @param array<string,mixed> $plan      The plan being activated.
	 *
	 * @return bool
	 */
	private function isOtherActivePlan(array $candidate, array $plan): bool {
		if (($candidate['lifecycle'] ?? '') !== 'active') {
			return false;
		}

		$sameIntake = (string)($candidate['programmeId'] ?? '') === (string)($plan['programmeId'] ?? '')
			&& (string)($candidate['intakeYear'] ?? '') === (string)($plan['intakeYear'] ?? '');

		return $this->planId(plan: $candidate) !== $this->planId(plan: $plan) && $sameIntake === true;
	}//end isOtherActivePlan()

	/**
	 * A plan's id, else its uuid, else ''.
	 *
	 * @param array<string,mixed> $plan A plan.
	 *
	 * @return string
	 */
	private function planId(array $plan): string {
		return (string)($plan['id'] ?? ($plan['uuid'] ?? ''));
	}//end planId()

	/**
	 * An ObjectService row as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()
}//end class
