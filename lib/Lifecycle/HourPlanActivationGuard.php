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
		$programmeId = (string)($object['programmeId'] ?? '');
		$intakeYear = (string)($object['intakeYear'] ?? '');
		if ($programmeId === '' || $intakeYear === '') {
			return GuardResult::deny('An hour plan needs a programme and an intake year before it can be activated.');
		}

		$self = (string)($object['id'] ?? ($object['uuid'] ?? ''));
		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA,
						'programmeId' => $programmeId,
						'intakeYear' => $intakeYear,
					],
				],
				_rbac: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[HourPlanActivationGuard] Could not read hour plans: {msg}', ['msg' => $exception->getMessage()]);
			return GuardResult::deny('The other hour plans of this programme could not be checked. Try again.');
		}

		$active = $this->activeSibling(rows: $rows, self: $self, programmeId: $programmeId, intakeYear: $intakeYear);
		if ($active !== null) {
			$this->logger->info(
				'[HourPlanActivationGuard] {user} tried a second active plan for {p} {y}.',
				['user' => $userId, 'p' => $programmeId, 'y' => $intakeYear]
			);
			return GuardResult::deny(sprintf('The hour plan "%s" is already active for this programme and intake year. Archive it first.', $active));
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The name of another active plan of the same programme and intake, or null.
	 *
	 * @param array<int,mixed> $rows        The plans of the programme and intake.
	 * @param string           $self        The plan being activated.
	 * @param string           $programmeId The programme.
	 * @param string           $intakeYear  The intake year.
	 *
	 * @return string|null The active plan's name (or id), or null when there is none.
	 */
	private function activeSibling(array $rows, string $self, string $programmeId, string $intakeYear): ?string {
		foreach ($rows as $row) {
			$plan = $this->toArray(row: $row);
			if (($plan['lifecycle'] ?? '') !== 'active') {
				continue;
			}

			$id = (string)($plan['id'] ?? ($plan['uuid'] ?? ''));
			$sameIntake = (string)($plan['programmeId'] ?? '') === $programmeId && (string)($plan['intakeYear'] ?? '') === $intakeYear;
			if ($id !== $self && $sameIntake === true) {
				return (string)($plan['name'] ?? $id);
			}
		}

		return null;
	}//end activeSibling()

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
