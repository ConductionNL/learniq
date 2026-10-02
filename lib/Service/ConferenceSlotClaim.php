<?php

/**
 * Learniq conference slot claim
 *
 * Takes one free ConferenceSlot for one child, so that two parents can never
 * end up with the same time and one family cannot book more times per child
 * than the round allows.
 *
 * The check and the write happen under two exclusive Nextcloud locks: one on
 * the slot and one on the child in the round. The slot's state is read from
 * storage INSIDE the locks (ObjectService::find keeps no copy of object data
 * between calls), so a second request always sees a booking the first one
 * wrote. A lock that is
 * already held is a refusal, not a wait: the parent picks another time or
 * tries again, which is better than a request hanging on another family's
 * booking.
 *
 * The locks are Nextcloud's own locking provider: the database by default,
 * or the configured memcache. With file locking switched off
 * (`filelocking.enabled` false) Nextcloud hands out a no-op provider, and the
 * re-read inside the claim is the only guard left.
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
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Claims a free conference slot for a child, atomically.
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */
class ConferenceSlotClaim {

	private const REGISTER = 'learniq';

	private const SLOT_SCHEMA = 'conference-slot';

	/**
	 * Slot states that count as a booking of the child.
	 */
	public const BOOKED_STATES = ['booked', 'acknowledged', 'completed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads and writes the slot.
	 * @param ILockingProvider $locks Nextcloud's locking provider.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ILockingProvider $locks,
	) {
	}//end __construct()

	/**
	 * Claim a slot for a child.
	 *
	 * @param string $slotId The slot the parent picked.
	 * @param array<string, mixed> $round The round (already checked open and direct).
	 * @param array<string, string> $booking learnerRef, learnerId, guardianRef and signupId to stamp.
	 *
	 * @return array{slot?: array<string, mixed>, refuse?: string} The booked slot, or why not.
	 *
	 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
	 */
	public function claim(string $slotId, array $round, array $booking): array {
		$roundId = (string)($round['id'] ?? '');
		$slotLock = 'learniq/conference-slot/' . $slotId;
		$childLock = 'learniq/conference-booking/' . $roundId . '/' . $booking['learnerRef'];
		$held = [];
		try {
			foreach ([$slotLock, $childLock] as $path) {
				$this->locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
				$held[] = $path;
			}

			return $this->claimLocked(slotId: $slotId, round: $round, booking: $booking);
		} catch (LockedException $exception) {
			return ['refuse' => 'slot-taken'];
		} finally {
			foreach ($held as $path) {
				$this->locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}//end claim()

	/**
	 * The check and the write, with both locks held.
	 *
	 * @param string $slotId The slot uuid.
	 * @param array<string, mixed> $round The round.
	 * @param array<string, string> $booking What to stamp.
	 *
	 * @return array{slot?: array<string, mixed>, refuse?: string}
	 */
	private function claimLocked(string $slotId, array $round, array $booking): array {
		$roundId = (string)($round['id'] ?? '');
		$found = $this->objectService->find(id: $slotId, register: self::REGISTER, schema: self::SLOT_SCHEMA, _rbac: false, _multitenancy: false);
		if ($found === null) {
			return ['refuse' => 'slot-unknown'];
		}

		$slot = $found->jsonSerialize();
		if ((string)($slot['conferenceRoundId'] ?? '') !== $roundId
			|| in_array($booking['learnerRef'], (array)($slot['eligibleLearnerRefs'] ?? []), true) === false
		) {
			return ['refuse' => 'slot-unknown'];
		}

		if (($slot['lifecycle'] ?? '') !== 'free') {
			return ['refuse' => 'slot-taken'];
		}

		if ($this->bookingsOf(roundId: $roundId, learnerRef: $booking['learnerRef']) >= ConferenceBookingMode::maxBookingsPerChild(round: $round)) {
			return ['refuse' => 'child-already-booked'];
		}

		$booked = array_merge(
			$slot,
			[
				'learnerId' => $booking['learnerId'],
				'learnerRef' => $booking['learnerRef'],
				'guardianRef' => $booking['guardianRef'],
				'signupId' => $booking['signupId'],
				'bookedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'lifecycle' => 'booked',
			]
		);
		unset($booked['@self'], $booked['id']);
		$saved = $this->objectService->saveObject(
			object: $booked,
			register: self::REGISTER,
			schema: self::SLOT_SCHEMA,
			uuid: $slotId,
			_rbac: false,
			_multitenancy: false
		);

		return ['slot' => array_merge($booked, $saved->jsonSerialize())];
	}//end claimLocked()

	/**
	 * How many times the child is booked in the round already.
	 *
	 * @param string $roundId The round uuid.
	 * @param string $learnerRef The child's LearnerProfile uuid.
	 *
	 * @return int
	 */
	private function bookingsOf(string $roundId, string $learnerRef): int {
		$rows = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SLOT_SCHEMA,
					'conferenceRoundId' => $roundId,
					'learnerRef' => $learnerRef,
				],
				'limit' => 100,
			],
			_rbac: false,
			_multitenancy: false
		);

		$count = 0;
		foreach ($rows as $row) {
			$data = is_array($row) === true ? $row : $row->jsonSerialize();
			if (in_array(($data['lifecycle'] ?? ''), self::BOOKED_STATES, true) === true) {
				$count++;
			}
		}

		return $count;
	}//end bookingsOf()
}//end class
