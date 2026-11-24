<?php

/**
 * Which stored rows the pupil page reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use JsonSerializable;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the queries and the filtering, over a store that ignores the filters.
 *
 * @covers \OCA\Learniq\Service\Pupil\PupilPageReader
 */
class PupilPageReaderTest extends TestCase {

	/**
	 * The queries sent, in order: config, rbac, multitenancy.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $queries = [];

	/**
	 * A reader whose store answers every query with the given rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return PupilPageReader
	 */
	private function reader(array $rows): PupilPageReader {
		$this->queries = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->queries[] = [$config, $_rbac, $_multitenancy];
				return $rows;
			}
		);

		return new PupilPageReader(objectService: $objectService);
	}//end reader()

	/**
	 * The profile is the one whose user id matches, read without per-object checks.
	 *
	 * @return void
	 */
	public function testProfileOfMatchesTheUserId(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialisation.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'p-vera', 'ncUserId' => 'vera'];
			}//end jsonSerialize()
		};
		$reader = $this->reader([['ncUserId' => 'sem'], 'junk', $entity]);

		$this->assertSame(['uuid' => 'p-vera', 'ncUserId' => 'vera', 'id' => 'p-vera'], $reader->profileOf(learnerId: 'vera'));
		$this->assertSame(
			[[['filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'vera'], 'limit' => 500], false, false]],
			$this->queries
		);
		$this->assertNull($reader->profileOf(learnerId: 'noor'));
	}//end testProfileOfMatchesTheUserId()

	/**
	 * A profile by id matches its id; an empty id reads nothing.
	 *
	 * @return void
	 */
	public function testProfileById(): void {
		$reader = $this->reader([['id' => 'other'], ['id' => 'fatima-uuid', 'fullName' => 'Fatima Hulstkamp']]);

		$this->assertSame(['id' => 'fatima-uuid', 'fullName' => 'Fatima Hulstkamp'], $reader->profileById(profileId: 'fatima-uuid'));
		$this->assertSame('fatima-uuid', $this->queries[0][0]['filters']['id']);
		$this->assertNull($reader->profileById(profileId: 'missing'));

		$this->queries = [];
		$this->assertNull($reader->profileById(profileId: ''));
		$this->assertSame([], $this->queries);
	}//end testProfileById()

	/**
	 * A row by id reads the asked schema and keeps only the row with that id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function testById(): void {
		$reader = $this->reader([['id' => 'off-wia', 'name' => 'Wiskunde A'], ['id' => 'off-other']]);

		$this->assertSame(expected: ['id' => 'off-wia', 'name' => 'Wiskunde A'], actual: $reader->byId(schema: 'elective-offer', id: 'off-wia'));
		$this->assertSame(expected: ['register' => 'learniq', 'schema' => 'elective-offer', 'id' => 'off-wia'], actual: $this->queries[0][0]['filters']);
		$this->assertNull(actual: $reader->byId(schema: 'elective-offer', id: 'missing'));

		$this->queries = [];
		$this->assertNull(actual: $reader->byId(schema: 'elective-offer', id: ''));
		$this->assertSame(expected: [], actual: $this->queries);
	}//end testById()

	/**
	 * A pupil's rows are only those whose learnerId is the pupil.
	 *
	 * @return void
	 */
	public function testOfPupilKeepsThePupilsRows(): void {
		$reader = $this->reader([['id' => 'f1', 'learnerId' => 'vera'], ['id' => 'f2', 'learnerId' => 'sem'], ['id' => 'f3']]);

		$this->assertSame([['id' => 'f1', 'learnerId' => 'vera']], $reader->ofPupil(schema: 'attendance-flag', learnerId: 'vera'));
		$this->assertSame(['register' => 'learniq', 'schema' => 'attendance-flag', 'learnerId' => 'vera'], $this->queries[0][0]['filters']);
	}//end testOfPupilKeepsThePupilsRows()

	/**
	 * Visits are those of the asked placements; no placements reads nothing.
	 *
	 * @return void
	 */
	public function testVisitsOfThePlacements(): void {
		$reader = $this->reader([['id' => 'v1', 'bpvPlacementId' => 'pl-1'], ['id' => 'v2', 'bpvPlacementId' => 'pl-9']]);

		$this->assertSame([['id' => 'v1', 'bpvPlacementId' => 'pl-1']], $reader->visitsOf(placementIds: ['pl-1', '']));
		$this->assertSame(['register' => 'learniq', 'schema' => 'bpv-visit', 'bpvPlacementId' => ['pl-1']], $this->queries[0][0]['filters']);

		$this->queries = [];
		$this->assertSame([], $reader->visitsOf(placementIds: ['']));
		$this->assertSame([], $this->queries);
	}//end testVisitsOfThePlacements()

	/**
	 * A group's rows come keyed by pupil, through one IN query with the
	 * group's bound; a profile is matched on its user id, and rows of other
	 * pupils or without a pupil are dropped.
	 *
	 * @return void
	 */
	public function testOfPupilsKeysTheRowsByPupil(): void {
		$reader = $this->reader([
			['id' => 's1', 'learnerId' => 'vera'],
			['id' => 's2', 'learnerId' => 'sem'],
			['id' => 's3', 'learnerId' => 'lina'],
			['id' => 's4', 'learnerId' => 'vera'],
			['id' => 's5'],
		]);

		$this->assertSame(
			['vera' => [['id' => 's1', 'learnerId' => 'vera'], ['id' => 's4', 'learnerId' => 'vera']], 'lina' => [['id' => 's3', 'learnerId' => 'lina']]],
			$reader->ofPupils(schema: 'attendance-summary', learnerIds: ['vera', 'lina', ''])
		);
		$this->assertSame(
			[[['filters' => ['register' => 'learniq', 'schema' => 'attendance-summary', 'learnerId' => ['vera', 'lina']], 'limit' => 5000], false, false]],
			$this->queries
		);

		$profiles = $this->reader([['id' => 'p1', 'ncUserId' => 'vera'], ['id' => 'p2', 'learnerId' => 'vera']]);
		$this->assertSame(['vera' => [['id' => 'p1', 'ncUserId' => 'vera']]], $profiles->ofPupils(schema: 'learner-profile', learnerIds: ['vera'], field: 'ncUserId'));
		$this->assertSame('ncUserId', array_key_last($this->queries[0][0]['filters']));

		$this->queries = [];
		$this->assertSame([], $profiles->ofPupils(schema: 'learner-profile', learnerIds: ['']));
		$this->assertSame([], $this->queries);
	}//end testOfPupilsKeysTheRowsByPupil()

	/**
	 * A row's id is its id, else its uuid, else ''.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$reader = $this->reader([]);

		$this->assertSame('a', $reader->idOf(row: ['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $reader->idOf(row: ['uuid' => 'b']));
		$this->assertSame('', $reader->idOf(row: ['id' => 7]));
		$this->assertSame('', $reader->idOf(row: []));
	}//end testIdOf()
}//end class
