<?php

/**
 * Repair step that writes the per-child invitation rows of every conference
 * round open for booking, for rounds opened before ConferenceInvitationSync.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\ConferenceInvitations;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the invitation rows of every round in `booking-open`.
 *
 * Idempotent: ConferenceInvitations writes only rows that are new or
 * changed, so a second run saves nothing. Runs without a session, so every
 * read and write passes `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */
class BackfillConferenceInvitations implements IRepairStep {

	private const REGISTER = 'learniq';

	private const ROUND_SCHEMA = 'conference-round';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the open rounds.
	 * @param ConferenceInvitations $invitations Writes each round's invitation rows.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ConferenceInvitations $invitations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function getName(): string {
		return 'Write one conference invitation per invited child for the rounds open for booking';
	}//end getName()

	/**
	 * Sync every open round.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function run(IOutput $output): void {
		$rounds = 0;
		$written = 0;
		try {
			$objects = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::ROUND_SCHEMA, 'lifecycle' => 'booking-open'],
					'limit' => 1000,
				],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($objects as $object) {
				$round = $object;
				if (is_array($round) === false) {
					$round = $object->jsonSerialize();
				}

				$rounds++;
				$written += $this->syncOne(round: $round);
			}
		} catch (Throwable $exception) {
			// OpenRegister absent or the register not imported yet: the next
			// upgrade retries.
			$this->logger->warning('[BackfillConferenceInvitations] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
		}//end try

		$output->info('BackfillConferenceInvitations: ' . $written . ' invitation(s) written for ' . $rounds . ' open round(s).');
	}//end run()

	/**
	 * Sync one round, logging a failure without stopping the others.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return int Rows written.
	 */
	private function syncOne(array $round): int {
		try {
			return $this->invitations->syncRound(round: $round);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillConferenceInvitations] Round {round} failed: {msg}',
				['round' => ($round['id'] ?? ''), 'msg' => $exception->getMessage()]
			);
			return 0;
		}
	}//end syncOne()
}//end class
