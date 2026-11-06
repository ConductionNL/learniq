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

		self::assertSame([null, null], array_column($writes, 'uuid'), 'two new rows');
		$byChild = array_column(array_column($writes, 'object'), null, 'learnerRef');
		self::assertSame('booked', $byChild[self::VERA]['status']);
		self::assertSame('open', $byChild[self::SAMI]['status']);
		self::assertSame(
			[
				'conferenceRoundId' => self::ROUND,
				'learnerRef' => self::SAMI,
				'roundName' => 'Oudergesprekken groep 4, oktober 2026',
				'bookingClosesAt' => '2026-10-16T17:00:00+02:00',
				'bookingMode' => 'direct',
				'tenant_id' => self::TENANT,
				'status' => 'open',
			],
			$byChild[self::SAMI]
		);
		foreach ($writes as $write) {
			self::assertNull(self::schemaError('conference-invitation', $write['object']), 'the real fragment accepts the row');
		}
	}//end testEachInvitedChildGetsARowAndOnlyTheOneWithoutATimeIsOpen()

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
		self::assertSame([], ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [self::VERA], existing: $existing));

		$writes = ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [self::VERA, self::SAMI], existing: $existing);
		self::assertSame(['inv-2'], array_column($writes, 'uuid'));
		self::assertSame('booked', $writes[0]['object']['status']);

		$writes = ConferenceInvitations::writesFor(round: $this->round(), takenRefs: [], existing: $existing);
		self::assertSame(['inv-1'], array_column($writes, 'uuid'), 'a time given back asks again');
		self::assertSame('open', $writes[0]['object']['status']);

		$round = array_merge($this->round(), ['invitedLearnerRefs' => [self::VERA]]);
		$writes = ConferenceInvitations::writesFor(round: $round, takenRefs: [self::VERA], existing: $existing);
		self::assertSame(['inv-2'], array_column($writes, 'uuid'));
		self::assertSame('closed', $writes[0]['object']['status']);
	}//end testOnlyChangedRowsAreWritten()

	/**
	 * A round that is not open for booking writes no new rows, and closes the
	 * open ones; a child with a time keeps `booked`.
	 *
	 * @return void
	 */
	public function testAClosedRoundAsksNothing(): void {
		$closed = array_merge($this->round(), ['lifecycle' => 'booking-closed']);
		self::assertSame([], ConferenceInvitations::writesFor(round: $closed, takenRefs: [], existing: []));

		$writes = ConferenceInvitations::writesFor(
			round: $closed,
			takenRefs: [self::VERA],
			existing: [$this->row(id: 'inv-1', child: self::VERA, status: 'booked'), $this->row(id: 'inv-2', child: self::SAMI, status: 'open')]
		);
		self::assertSame([['inv-2', 'closed']], array_map(static fn (array $w): array => [$w['uuid'], $w['object']['status']], $writes));
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
		$service = new ConferenceInvitations($this->objectService(store: $store));

		self::assertSame(2, $service->syncRound(round: $this->round()));
		$statuses = array_column($store->rows['conference-invitation'], 'status', 'learnerRef');
		self::assertSame([self::VERA => 'booked', self::SAMI => 'open'], $statuses);

		self::assertSame(0, $service->syncRound(round: $this->round()), 'a second run writes nothing');
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
		self::assertNotEmpty($objects['conference-round']);
		foreach ($objects['conference-round'] as $round) {
			$round = $strip($round);
			$taken = [];
			foreach ($objects['conference-slot'] as $slot) {
				if ($slot['conferenceRoundId'] === $round['id'] && isset($slot['learnerRef']) === true && in_array($slot['lifecycle'] ?? '', ConferenceInvitations::TIME_TAKEN, true) === true) {
					$taken[] = $slot['learnerRef'];
				}
			}

			$own = array_values(array_filter($invitations, static fn (array $row): bool => $row['conferenceRoundId'] === $round['id']));
			self::assertCount(count($round['invitedLearnerRefs']), $own, $round['name']);
			self::assertSame([], ConferenceInvitations::writesFor(round: $round, takenRefs: $taken, existing: $own), $round['name'].' is in step');
		}

		$statuses = array_column($invitations, 'status', 'learnerRef');
		self::assertSame('booked', $statuses['ee010008-0000-4000-8000-000000000415'], "Vera's time is acknowledged");
		self::assertSame(1, count(array_filter($invitations, static fn (array $row): bool => $row['status'] === 'booked')));
		foreach ($invitations as $row) {
			unset($row['id']);
			self::assertNull(self::schemaError('conference-invitation', $row));
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
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config, bool $_rbac=true, bool $_multitenancy=true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) => $store->save((string)$schema, $object, $uuid, false)
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend=[], bool $files=false, $register=null, $schema=null) use ($store) {
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
}//end class
