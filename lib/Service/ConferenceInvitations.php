<?php

/**
 * Learniq ConferenceInvitations
 *
 * Keeps one `conference-invitation` row per invited child per conference
 * round, so the guardian's overview can list a task per child ("Kies een
 * tijd voor het oudergesprek van Sami") instead of one per round. A round
 * names its invited pupils in a list (`invitedLearnerRefs`), and portaliq
 * neither projects a list into rows nor reads a collection a provider
 * computes, so the rows are stored and kept in step here.
 *
 * Each row's `status`:
 * - `booked` once the child has a conversation time in the round (a slot of
 *   the round naming the child in a state that holds a time);
 * - otherwise `open` while the round is `booking-open`;
 * - otherwise `closed`, as is the row of a child no longer invited.
 *
 * A row is only created when it would be open or booked: a round that never
 * opened, or one long closed, gets no rows. The round itself is never
 * changed.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Writes the per-child invitation rows of a conference round.
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */
class ConferenceInvitations {

	public const SCHEMA = 'conference-invitation';

	private const REGISTER = 'learniq';

	private const SLOT_SCHEMA = 'conference-slot';

	/**
	 * Slot states that mean the child has a conversation time.
	 */
	public const TIME_TAKEN = ['booked', 'acknowledged', 'proposed', 'confirmed', 'completed'];

	/**
	 * The round fields an invitation copies, under its own name.
	 */
	private const COPIES = [
		'name' => 'roundName',
		'bookingClosesAt' => 'bookingClosesAt',
	];

	/**
	 * The booking modes the schema accepts.
	 */
	private const MODES = ['direct', 'preference'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the round's slots and invitations; writes the invitations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Bring the invitation rows of one round in step with the round and its
	 * conversation times.
	 *
	 * @param array<string, mixed> $round The round.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function syncRound(array $round): int {
		$roundId = (string)($round['id'] ?? '');
		if ($roundId === '') {
			return 0;
		}

		$writes = self::writesFor(
			round: $round,
			takenRefs: $this->takenRefs(roundId: $roundId),
			existing: $this->rows(schema: self::SCHEMA, roundId: $roundId)
		);
		foreach ($writes as $write) {
			$this->objectService->saveObject(
				object: $write['object'],
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $write['uuid'],
				_rbac: false,
				_multitenancy: false
			);
		}

		return count($writes);
	}//end syncRound()

	/**
	 * The rows to write for a round: a new or changed row per invited child,
	 * and `closed` for a child no longer invited. Unchanged rows are left out.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param array<int, string> $takenRefs The children who have a time in the round.
	 * @param array<int, array<string, mixed>> $existing The round's current invitation rows.
	 *
	 * @return array<int, array{uuid: string|null, object: array<string, mixed>}>
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public static function writesFor(array $round, array $takenRefs, array $existing): array {
		$byChild = [];
		foreach ($existing as $row) {
			$byChild[(string)($row['learnerRef'] ?? '')] = $row;
		}

		$invited = array_values(array_unique(array_filter((array)($round['invitedLearnerRefs'] ?? []), 'is_string')));
		$writes = [];
		foreach ($invited as $learnerRef) {
			$write = self::writeFor(
				round: $round,
				learnerRef: $learnerRef,
				taken: in_array($learnerRef, $takenRefs, true),
				row: ($byChild[$learnerRef] ?? null)
			);
			if ($write !== null) {
				$writes[] = $write;
			}
		}

		// A child no longer invited is asked nothing any more.
		foreach ($byChild as $learnerRef => $row) {
			if (in_array($learnerRef, $invited, true) === false && ($row['status'] ?? '') !== 'closed') {
				$writes[] = ['uuid' => self::uuidOf(row: $row), 'object' => array_merge($row, ['status' => 'closed'])];
			}
		}

		return $writes;
	}//end writesFor()

	/**
	 * The write for one invited child, or null when the row is in step or
	 * would be a new closed row.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $learnerRef The child.
	 * @param bool $taken Whether the child has a time in the round.
	 * @param array<string, mixed>|null $row The child's current row, or null.
	 *
	 * @return array{uuid: string|null, object: array<string, mixed>}|null
	 */
	private static function writeFor(array $round, string $learnerRef, bool $taken, ?array $row): ?array {
		$status = self::statusFor(round: $round, taken: $taken);
		if ($row === null && $status === 'closed') {
			return null;
		}

		$object = array_merge(($row ?? []), self::fields(round: $round, learnerRef: $learnerRef), ['status' => $status]);
		if ($row !== null && $object === $row) {
			return null;
		}

		return ['uuid' => self::uuidOf(row: $row), 'object' => $object];
	}//end writeFor()

	/**
	 * A child's status in a round.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param bool $taken Whether the child has a time in the round.
	 *
	 * @return string `booked`, `open` or `closed`.
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public static function statusFor(array $round, bool $taken): string {
		if ($taken === true) {
			return 'booked';
		}

		if (($round['lifecycle'] ?? '') === 'booking-open') {
			return 'open';
		}

		return 'closed';
	}//end statusFor()

	/**
	 * The fields an invitation takes from its round.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param string $learnerRef The invited child.
	 *
	 * @return array<string, mixed>
	 */
	private static function fields(array $round, string $learnerRef): array {
		$fields = [
			'conferenceRoundId' => (string)$round['id'],
			'learnerRef' => $learnerRef,
		];
		foreach (self::COPIES as $from => $to) {
			if (is_string($round[$from] ?? null) === true && $round[$from] !== '') {
				$fields[$to] = $round[$from];
			}
		}

		// An enum takes no null: a round without a mode leaves the key out.
		if (in_array(($round['bookingMode'] ?? null), self::MODES, true) === true) {
			$fields['bookingMode'] = $round['bookingMode'];
		}

		if (is_string($round['tenant_id'] ?? null) === true && $round['tenant_id'] !== '') {
			$fields['tenant_id'] = $round['tenant_id'];
		}

		return $fields;
	}//end fields()

	/**
	 * The uuid of a stored row, or null for a new one.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return string|null
	 */
	private static function uuidOf(?array $row): ?string {
		if ($row === null) {
			return null;
		}

		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return null;
	}//end uuidOf()

	/**
	 * The children who have a conversation time in the round.
	 *
	 * @param string $roundId The round.
	 *
	 * @return array<int, string>
	 */
	private function takenRefs(string $roundId): array {
		$refs = [];
		foreach ($this->rows(schema: self::SLOT_SCHEMA, roundId: $roundId) as $slot) {
			$learnerRef = ($slot['learnerRef'] ?? null);
			if (is_string($learnerRef) === true && $learnerRef !== ''
				&& in_array(($slot['lifecycle'] ?? ''), self::TIME_TAKEN, true) === true
			) {
				$refs[] = $learnerRef;
			}
		}

		return array_values(array_unique($refs));
	}//end takenRefs()

	/**
	 * The rows of one schema in one round, as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param string $roundId The round.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, string $roundId): array {
		$objects = $this->objectService->findAll(
			[
				'filters' => ['register' => self::REGISTER, 'schema' => $schema, 'conferenceRoundId' => $roundId],
				'limit' => 5000,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === false) {
				$object = $object->jsonSerialize();
			}

			$rows[] = $object;
		}

		return $rows;
	}//end rows()
}//end class
