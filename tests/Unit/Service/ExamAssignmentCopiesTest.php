<?php

/**
 * An assessor assignment reads its candidate, start, end, room and exam off the row.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReadableCopies;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Ruud's assignment for Daan at the 9.00 sitting of "Storingen zoeken".
 *
 * @covers \OCA\Learniq\Service\ReadableCopies
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class ExamAssignmentCopiesTest extends TestCase {

	private const DAAN = 'ee010008-0000-4000-8000-000000000501';

	private const SITTING = 'ee010008-0000-4000-8000-000000000601';

	/**
	 * The copies over a store with Daan, the sitting and its assessment.
	 *
	 * @return ReadableCopies
	 */
	private function copies(): ReadableCopies {
		$store = new RegisterFaithfulStore();
		$store->rows = [
			'learner-profile' => [['id' => self::DAAN, 'givenName' => 'Daan', 'familyName' => 'de Vries']],
			'exam-sitting' => [
				[
					'id' => self::SITTING,
					'assessmentId' => 'as-1',
					'startsAt' => '2026-11-16T09:00:00+01:00',
					'endsAt' => '2026-11-16T12:00:00+01:00',
					'roomIds' => ['room-w2', 'room-gone', 'room-w3'],
				],
				['id' => 'sitting-no-rooms', 'assessmentId' => 'as-1', 'startsAt' => '2026-11-17T09:00:00+01:00', 'roomIds' => ['room-unnamed']],
			],
			'exam' => [['id' => 'as-1', 'title' => 'Storingen zoeken aan warmtepompen']],
			'room' => [
				['id' => 'room-w2', 'name' => 'Werkplaats W2'],
				['id' => 'room-w3', 'name' => 'Werkplaats W3'],
				['id' => 'room-unnamed', 'name' => '  '],
			],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);

		return new ReadableCopies(objectService: $objectService, users: $this->createMock(IUserManager::class));
	}//end copies()

	/**
	 * The assignment is covered and reads all three copies.
	 *
	 * @return void
	 */
	public function testTheAssignmentReadsItsCandidateStartAndExam(): void {
		$copies = $this->copies();

		self::assertTrue($copies->covers('exam-assessor-assignment'));
		self::assertSame(
			expected: ['learnerName', 'sittingStartsAt', 'examTitle', 'sittingEndsAt', 'sittingRoom'],
			actual: $copies->fields(slug: 'exam-assessor-assignment')
		);
		self::assertSame(
			expected: [
				'learnerName' => 'Daan de Vries',
				'sittingStartsAt' => '2026-11-16T09:00:00+01:00',
				'examTitle' => 'Storingen zoeken aan warmtepompen',
				'sittingEndsAt' => '2026-11-16T12:00:00+01:00',
				// The sitting's rooms in its own order; a room that cannot be read is left out.
				'sittingRoom' => 'Werkplaats W2, Werkplaats W3',
			],
			actual: $copies->derive(
				slug: 'exam-assessor-assignment',
				row: ['learnerRef' => self::DAAN, 'examSittingId' => self::SITTING, 'learnerName' => 'Sent by the client', 'sittingRoom' => 'Sent by the client']
			)
		);
	}//end testTheAssignmentReadsItsCandidateStartAndExam()

	/**
	 * A sitting without an end and with only a nameless room leaves both copies empty.
	 *
	 * @return void
	 */
	public function testASittingWithoutAnEndOrANamedRoomLeavesThoseCopiesEmpty(): void {
		$derived = $this->copies()->derive(slug: 'exam-assessor-assignment', row: ['learnerRef' => self::DAAN, 'examSittingId' => 'sitting-no-rooms']);

		self::assertSame(expected: '2026-11-17T09:00:00+01:00', actual: $derived['sittingStartsAt']);
		self::assertNull(actual: $derived['sittingEndsAt']);
		self::assertNull(actual: $derived['sittingRoom']);
	}//end testASittingWithoutAnEndOrANamedRoomLeavesThoseCopiesEmpty()

	/**
	 * A sitting that names nothing leaves its copies empty; the candidate is still named.
	 *
	 * @return void
	 */
	public function testAnUnknownSittingLeavesItsCopiesEmpty(): void {
		self::assertSame(
			['learnerName' => 'Daan de Vries', 'sittingStartsAt' => null, 'examTitle' => null, 'sittingEndsAt' => null, 'sittingRoom' => null],
			$this->copies()->derive('exam-assessor-assignment', ['learnerRef' => self::DAAN, 'examSittingId' => 'missing'])
		);
		self::assertSame(
			['learnerName' => null, 'sittingStartsAt' => null, 'examTitle' => null, 'sittingEndsAt' => null, 'sittingRoom' => null],
			$this->copies()->derive('exam-assessor-assignment', [])
		);
	}//end testAnUnknownSittingLeavesItsCopiesEmpty()
}//end class
