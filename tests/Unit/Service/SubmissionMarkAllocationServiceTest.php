<?php

/**
 * Learniq SubmissionMarkAllocationService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/assignments/spec.md#requirement-the-teacher-in-charge-allocates-markers
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SubmissionMarkAllocationService;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Allocation over a register-faithful store, so an undeclared filter key or a
 * wrong schema slug reads nothing and fails the test.
 */
class SubmissionMarkAllocationServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The service over a store holding two handed-in submissions and a draft.
	 *
	 * @return SubmissionMarkAllocationService
	 */
	private function service(): SubmissionMarkAllocationService {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['submission'] = [
			['id' => 'sub-1', 'assignmentId' => 'asg-1', 'learnerIds' => ['pupil-1'], 'lifecycle' => 'submitted', 'tenant_id' => 't1'],
			['id' => 'sub-2', 'assignmentId' => 'asg-1', 'learnerIds' => ['pupil-2', 's.jansen'], 'lifecycle' => 'late', 'tenant_id' => 't1'],
			['id' => 'sub-3', 'assignmentId' => 'asg-1', 'learnerIds' => ['pupil-3'], 'lifecycle' => 'draft', 'tenant_id' => 't1'],
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new SubmissionMarkAllocationService(objectService: $objects);
	}//end service()

	/**
	 * The assignment under test.
	 *
	 * @param int $markers markersPerSubmission.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(int $markers = 2): array {
		return ['id' => 'asg-1', 'markersPerSubmission' => $markers];
	}//end assignment()

	/**
	 * The markers recorded per (submission, marker).
	 *
	 * @return array<int, string>
	 */
	private function pairs(): array {
		$pairs = [];
		foreach (($this->store->rows['submission-mark'] ?? []) as $mark) {
			$pairs[] = $mark['submissionId'] . '/' . $mark['markerId'];
		}

		sort($pairs);
		return $pairs;
	}//end pairs()

	/**
	 * Two markers on every handed-in submission, drafts skipped, the union
	 * written to the submission, and a second run adds nothing.
	 *
	 * @return void
	 */
	public function testAllocatesEveryHandedInSubmissionOnceOnly(): void {
		$service = $this->service();

		$first = $service->allocate(assignment: $this->assignment(), markerIds: ['j.devries', 'a.bakker']);
		self::assertNull($first['error']);
		self::assertSame(2, $first['submissionsProcessed']);
		self::assertSame(4, $first['createdCount']);
		self::assertSame(['sub-1/a.bakker', 'sub-1/j.devries', 'sub-2/a.bakker', 'sub-2/j.devries'], $this->pairs());

		foreach ($this->store->rows['submission-mark'] as $mark) {
			self::assertSame('draft', $mark['lifecycle']);
			self::assertSame('asg-1', $mark['assignmentId']);
			self::assertSame('t1', $mark['tenant_id']);
		}

		$byId = array_column($this->store->rows['submission'], null, 'id');
		self::assertSame(['j.devries', 'a.bakker'], $byId['sub-1']['markerIds']);
		self::assertArrayNotHasKey('markerIds', $byId['sub-3']);

		$again = $service->allocate(assignment: $this->assignment(), markerIds: ['j.devries', 'a.bakker']);
		self::assertSame(0, $again['createdCount']);
		self::assertCount(4, $this->store->rows['submission-mark']);
	}//end testAllocatesEveryHandedInSubmissionOnceOnly()

	/**
	 * A marker who is one of the submission's learners is refused there, with
	 * the submission named, and still allocated elsewhere.
	 *
	 * @return void
	 */
	public function testRefusesAMarkerWhoIsALearnerOfTheSubmission(): void {
		$result = $this->service()->allocate(assignment: $this->assignment(), markerIds: ['s.jansen']);

		self::assertSame([['submissionId' => 'sub-2', 'markerId' => 's.jansen', 'reason' => 'marker-is-learner']], $result['refused']);
		self::assertSame(['sub-1/s.jansen'], $this->pairs());
	}//end testRefusesAMarkerWhoIsALearnerOfTheSubmission()

	/**
	 * More markers than the assignment asks for is refused, per request and
	 * per submission once earlier markers fill it.
	 *
	 * @return void
	 */
	public function testRefusesMoreMarkersThanTheAssignmentAsksFor(): void {
		$service = $this->service();
		self::assertSame('too-many-markers', $service->allocate(assignment: $this->assignment(), markerIds: ['a', 'b', 'c'])['error']);
		self::assertSame([], $this->pairs());

		$service->allocate(assignment: $this->assignment(), markerIds: ['a', 'b'], submissionId: 'sub-1');
		$third = $service->allocate(assignment: $this->assignment(), markerIds: ['c'], submissionId: 'sub-1');
		self::assertSame([['submissionId' => 'sub-1', 'markerId' => 'c', 'reason' => 'submission-full']], $third['refused']);
		self::assertSame(['sub-1/a', 'sub-1/b'], $this->pairs());
	}//end testRefusesMoreMarkersThanTheAssignmentAsksFor()

	/**
	 * A single-marker assignment, an empty marker list and an unknown or draft
	 * submission are refused without a write.
	 *
	 * @return void
	 */
	public function testRefusalsOfTheWholeRequestWriteNothing(): void {
		$service = $this->service();
		self::assertSame('single-marker', $service->allocate(assignment: $this->assignment(markers: 1), markerIds: ['a'])['error']);
		self::assertSame('no-markers', $service->allocate(assignment: $this->assignment(), markerIds: ['', ' '])['error']);
		self::assertSame('submission-not-found', $service->allocate(assignment: $this->assignment(), markerIds: ['a'], submissionId: 'sub-3')['error']);
		self::assertSame([], $this->store->saves);
	}//end testRefusalsOfTheWholeRequestWriteNothing()
}//end class
