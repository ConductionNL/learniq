<?php

/**
 * ConferenceInvitations test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ConferenceInvitations;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * One invitation row per invited child: open while the child has no time in
 * an open round, booked once it has one, closed otherwise.
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */
class ConferenceInvitationsTest extends TestCase {

	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const ROUND = 'ab000001-0000-4000-8000-000000000001';

	private const VERA = 'ab000002-0000-4000-8000-000000000001';

	private const SAMI = 'ab000002-0000-4000-8000-000000000002';

	private const DAAN = 'ab000002-0000-4000-8000-000000000003';

	/**
	 * Each invited child gets a row; the child with a time is booked, the
	 * other open; every row passes the real schema fragment.
	 *
	 * @return void
	 */
	public function testEachInvitedChildGetsARowAndOnlyTheOneWithoutATimeIsOpen(): void {
		$writes = ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [self::VERA], existing: []);

		self::assertSame(expected: [null, null], actual: array_column($writes, 'uuid'), message: 'two new rows');
		$byChild = array_column(array_column($writes, 'object'), null, 'learnerRef');
		self::assertSame(expected: 'booked', actual: $byChild[self::VERA]['status']);
		self::assertSame(expected: 'open', actual: $byChild[self::SAMI]['status']);
		self::assertSame(
			expected: [
				'conferenceRoundId' => self::ROUND,
				'learnerRef' => self::SAMI,
				'roundName' => 'Oudergesprekken groep 4, oktober 2026',
				'bookingClosesAt' => '2026-10-16T17:00:00+02:00',
				'bookingMode' => 'direct',
				'tenant_id' => self::TENANT,
				'status' => 'open',
			],
			actual: $byChild[self::SAMI]
		);
		foreach ($writes as $write) {
			self::assertNull(actual: self::schemaError(slug: 'conference-invitation', payload: $write['object']), message: 'the real fragment accepts the row');
		}
	}//end testEachInvitedChildGetsARowAndOnlyTheOneWithoutATimeIsOpen()

	/**
	 * A round that lets pupils book marks each invitation so her portal task
	 * shows; switched off again, the mark is taken back on rows that had it,
	 * and rows of other rounds never gain the key.
	 *
	 * @return void
	 */
	public function testARoundThatLetsPupilsBookMarksItsInvitations(): void {
		$round = array_merge($this->round(), ['pupilMayBook' => true]);
		$writes = ConferenceInvitations::writesFor(round: $round, takenRefs: [], existing: []);
		self::assertSame(expected: [true, true], actual: array_column(array_column($writes, 'object'), 'pupilMayBook'));
		foreach ($writes as $write) {
			self::assertNull(actual: self::schemaError(slug: 'conference-invitation', payload: $write['object']), message: 'the real fragment accepts the mark');
		}

		$marked = array_merge($this->row(id: 'inv-2', child: self::SAMI, status: 'open'), ['pupilMayBook' => true]);
		$off = ConferenceInvitations::writesFor(
			round: $this->round(),
			takenRefs: [self::VERA],
			existing: [$this->row(id: 'inv-1', child: self::VERA, status: 'booked'), $marked]
		);
		self::assertSame(expected: ['inv-2'], actual: array_column($off, 'uuid'));
		self::assertFalse(condition: $off[0]['object']['pupilMayBook']);

		foreach (ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [], existing: []) as $write) {
			self::assertArrayNotHasKey(key: 'pupilMayBook', array: $write['object']);
		}
	}//end testARoundThatLetsPupilsBookMarksItsInvitations()

	/**
	 * Nothing changed, nothing is written; a time booked or given back moves
	 * only that child's row; a child no longer invited is closed.
	 *
	 * @return void
	 */
	public function testOnlyChangedRowsAreWritten(): void {
		$existing = [
			$this->row(id: 'inv-1', child: self::VERA, status: 'booked'),
			$this->row(id: 'inv-2', child: self::SAMI, status: 'open'),
		];
		self::assertSame(expected: [], actual: ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [self::VERA], existing: $existing));

		$writes = ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [self::VERA, self::SAMI], existing: $existing);
		self::assertSame(expected: ['inv-2'], actual: array_column($writes, 'uuid'));
		self::assertSame(expected: 'booked', actual: $writes[0]['object']['status']);

		$writes = ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [], existing: $existing);
		self::assertSame(expected: ['inv-1'], actual: array_column($writes, 'uuid'), message: 'a time given back asks again');
		self::assertSame(expected: 'open', actual: $writes[0]['object']['status']);

		$round = array_merge($this->round(), ['invitedLearnerRefs' => [self::VERA]]);
		$writes = ConferenceInvitations::writesFor(round: $round, takenRefs: [self::VERA], existing: $existing);
		self::assertSame(expected: ['inv-2'], actual: array_column($writes, 'uuid'));
		self::assertSame(expected: 'closed', actual: $writes[0]['object']['status']);
	}//end testOnlyChangedRowsAreWritten()

	/**
	 * A round that is not open for booking writes no new rows, and closes the
	 * open ones; a child with a time keeps `booked`.
	 *
	 * @return void
	 */
	public function testAClosedRoundAsksNothing(): void {
		$closed = array_merge($this->round(), ['lifecycle' => 'booking-closed']);
		self::assertSame(expected: [], actual: ConferenceInvitations::writesFor(round: $closed, takenRefs: [], existing: []));

		$writes = ConferenceInvitations::writesFor(
			round: $closed,
			takenRefs: [self::VERA],
			existing: [$this->row(id: 'inv-1', child: self::VERA, status: 'booked'), $this->row(id: 'inv-2', child: self::SAMI, status: 'open')]
		);
		self::assertSame(expected: [['inv-2', 'closed']], actual: array_map(static fn (array $w): array => [$w['uuid'], $w['object']['status']], $writes));
	}//end testAClosedRoundAsksNothing()

	/**
	 * Through the register: the round's slots decide who has a time, only the
	 * round's own rows are read, and a second sync writes nothing.
	 *
	 * @return void
	 */
	public function testSyncRoundReadsTheRoundsTimesAndIsIdempotent(): void {
		$store = new RegisterFaithfulStore();
		$store->rows['conference-slot'] = [
			$this->slot(id: 'slot-1', child: self::VERA, state: 'acknowledged'),
			$this->slot(id: 'slot-2', child: self::SAMI, state: 'cancelled'),
			$this->slot(id: 'slot-3', child: self::DAAN, state: 'booked', round: 'ab000001-0000-4000-8000-000000000099'),
		];
		$service = new ConferenceInvitations(objectService: $this->objectService(store: $store));

		self::assertSame(expected: 2, actual: $service->syncRound(round: $this->round()));
		$statuses = array_column($store->rows['conference-invitation'], 'status', 'learnerRef');
		self::assertSame(expected: [self::VERA => 'booked', self::SAMI => 'open'], actual: $statuses);

		self::assertSame(expected: 0, actual: $service->syncRound(round: $this->round()), message: 'a second run writes nothing');
	}//end testSyncRoundReadsTheRoundsTimesAndIsIdempotent()

	/**
	 * The po set's seeded invitations are what the service would write: one
	 * row per invited child of each open round, Vera's booked through her
	 * acknowledged time and Sami's open, every row valid against the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
	 */
	public function testTheSeededInvitationsAreWhatTheServiceWrites(): void {
		$objects = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/profiles/po.json'), true)['x-openregister']['seedData']['objects'];
		$strip = static function (array $row): array {
			$row['id'] = $row['uuid'];
			unset($row['@self'], $row['uuid'], $row['slug']);
			return $row;
		};
		$invitations = array_map($strip, $objects['conference-invitation']);
		self::assertNotEmpty(actual: $objects['conference-round']);
		foreach ($objects['conference-round'] as $round) {
			$round = $strip($round);
			$taken = [];
			foreach ($objects['conference-slot'] as $slot) {
				if ($slot['conferenceRoundId'] === $round['id'] && isset($slot['learnerRef']) === true && in_array($slot['lifecycle'] ?? '', ConferenceInvitations::TIME_TAKEN, true) === true) {
					$taken[] = $slot['learnerRef'];
				}
			}

			$own = array_values(array_filter($invitations, static fn (array $row): bool => $row['conferenceRoundId'] === $round['id']));
			self::assertCount(expectedCount: count($round['invitedLearnerRefs']), haystack: $own, message: $round['name']);
			self::assertSame(expected: [], actual: ConferenceInvitations::writesFor(round: $round, takenRefs: $taken, existing: $own), message: $round['name'].' is in step');
		}

		$statuses = array_column($invitations, 'status', 'learnerRef');
		self::assertSame(expected: 'booked', actual: $statuses['ee010008-0000-4000-8000-000000000415'], message: "Vera's time is acknowledged");
		self::assertSame(expected: 1, actual: count(array_filter($invitations, static fn (array $row): bool => $row['status'] === 'booked')));
		foreach ($invitations as $row) {
			unset($row['id']);
			self::assertNull(actual: self::schemaError(slug: 'conference-invitation', payload: $row));
		}
	}//end testTheSeededInvitationsAreWhatTheServiceWrites()

	/**
	 * Groep 4's open round, Vera and Sami invited.
	 *
	 * @return array<string, mixed>
	 */
	private function round(): array {
		return [
			'id' => self::ROUND,
			'name' => 'Oudergesprekken groep 4, oktober 2026',
			'bookingClosesAt' => '2026-10-16T17:00:00+02:00',
			'bookingMode' => 'direct',
			'lifecycle' => 'booking-open',
			'invitedLearnerRefs' => [self::VERA, self::SAMI],
			'tenant_id' => self::TENANT,
		];
	}//end round()

	/**
	 * A stored invitation row.
	 *
	 * @param string $id The row id.
	 * @param string $child The child.
	 * @param string $status The status.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $id, string $child, string $status): array {
		return [
			'id' => $id,
			'conferenceRoundId' => self::ROUND,
			'learnerRef' => $child,
			'roundName' => 'Oudergesprekken groep 4, oktober 2026',
			'bookingClosesAt' => '2026-10-16T17:00:00+02:00',
			'bookingMode' => 'direct',
			'tenant_id' => self::TENANT,
			'status' => $status,
		];
	}//end row()

	/**
	 * A conversation time of a child.
	 *
	 * @param string $id The slot id.
	 * @param string $child The child.
	 * @param string $state The slot state.
	 * @param string $round The round.
	 *
	 * @return array<string, mixed>
	 */
	private function slot(string $id, string $child, string $state, string $round=self::ROUND): array {
		return [
			'id' => $id,
			'conferenceRoundId' => $round,
			'teacherId' => 'po-leerkracht-09',
			'startsAt' => '2026-10-29T18:00:00+01:00',
			'endsAt' => '2026-10-29T18:10:00+01:00',
			'learnerRef' => $child,
			'lifecycle' => $state,
			'tenant_id' => self::TENANT,
		];
	}//end slot()

	/**
	 * An ObjectService over the in-memory register.
	 *
	 * @param RegisterFaithfulStore $store The register.
	 *
	 * @return ObjectService
	 */
	private function objectService(RegisterFaithfulStore $store): ObjectService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config, bool $_rbac=true, bool $_multitenancy=true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) => $store->save((string)($options->target->schema), $object, ($options->uuid), false)
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?ObjectFindOptions $options = null) use ($store) {
				$_extend = $options?->extend ?? [];
				$files = $options?->files ?? false;
				$register = $options?->register;
				$schema = $options?->schema;
				foreach (($store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * The fields an invitation copies from its round, and the uuid of a
	 * stored row: blank or non-string values and unknown modes are left out,
	 * a row is named by its id, else its uuid.
	 *
	 * @return void
	 */
	public function testTheCopiedFieldsAndTheStoredUuid(): void {
		$fields = new \ReflectionMethod(ConferenceInvitations::class, 'fields');
		$uuidOf = new \ReflectionMethod(ConferenceInvitations::class, 'uuidOf');

		self::assertSame(
			expected: [
				'conferenceRoundId' => '7',
				'learnerRef' => 'lp-1',
				'roundName' => 'Najaar',
				'bookingClosesAt' => '2026-10-01',
				'bookingMode' => 'preference',
				'tenant_id' => 't1',
			],
			actual: $fields->invoke(null, ['id' => 7, 'name' => 'Najaar', 'bookingClosesAt' => '2026-10-01', 'bookingMode' => 'preference', 'tenant_id' => 't1', 'other' => 'x'], 'lp-1')
		);
		self::assertSame(
			expected: ['conferenceRoundId' => 'r', 'learnerRef' => 'lp-2'],
			actual: $fields->invoke(null, ['id' => 'r', 'name' => '', 'bookingClosesAt' => 5, 'bookingMode' => 'walk-in', 'tenant_id' => ''], 'lp-2')
		);
		self::assertSame(
			expected: ['conferenceRoundId' => 'r', 'learnerRef' => 'lp-3', 'bookingMode' => 'direct'],
			actual: $fields->invoke(null, ['id' => 'r', 'bookingMode' => 'direct', 'tenant_id' => 9], 'lp-3')
		);

		self::assertNull(actual: $uuidOf->invoke(null, null));
		self::assertSame(expected: 'a', actual: $uuidOf->invoke(null, ['id' => 'a', 'uuid' => 'b']));
		self::assertSame(expected: 'b', actual: $uuidOf->invoke(null, ['uuid' => 'b']));
		self::assertNull(actual: $uuidOf->invoke(null, ['id' => '', 'uuid' => 'b']));
		self::assertNull(actual: $uuidOf->invoke(null, ['id' => 5]));
		self::assertNull(actual: $uuidOf->invoke(null, []));
	}//end testTheCopiedFieldsAndTheStoredUuid()
}//end class
