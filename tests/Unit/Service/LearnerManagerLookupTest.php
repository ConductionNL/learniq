<?php

/**
 * LearnerManagerLookup unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LearnerManagerLookup;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for LearnerManagerLookup.
 *
 * @covers \OCA\Learniq\Service\LearnerManagerLookup
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class LearnerManagerLookupTest extends TestCase {

	/**
	 * The reads the lookup made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * The lookup over the given profiles.
	 *
	 * @param array<int, array<string, mixed>>|null $profiles The profiles found; null: the read throws.
	 *
	 * @return LearnerManagerLookup
	 */
	private function lookup(?array $profiles): LearnerManagerLookup {
		$this->reads = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($profiles): array {
				$this->reads[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($profiles === null) {
					throw new RuntimeException('profiles unreadable');
				}

				return array_map(static fn (array $p) => OrEntityFactory::make($p, 'learner-profile'), $profiles);
			}
		);

		return new LearnerManagerLookup(objectService: $objects);
	}//end lookup()

	/**
	 * The manager of the pupil's own profile, read as the system by user id.
	 *
	 * @return void
	 */
	public function testReturnsTheManagerOfThePupilsProfile(): void {
		$lookup = $this->lookup(
			[
				['id' => 'p-2', 'ncUserId' => 'pupil-2', 'managerId' => 'mentor-2'],
				['id' => 'p-1', 'ncUserId' => 'pupil-1', 'managerId' => 'mentor-1'],
			]
		);

		self::assertSame('mentor-1', $lookup->forLearner(learnerId: 'pupil-1'));
		self::assertCount(1, $this->reads);
		self::assertSame(
			['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'pupil-1'],
			$this->reads[0]['config']['filters']
		);
		self::assertFalse($this->reads[0]['rbac']);
		self::assertFalse($this->reads[0]['multitenancy']);
	}//end testReturnsTheManagerOfThePupilsProfile()

	/**
	 * No profile, another pupil's profile only, or an empty manager: null.
	 *
	 * @return void
	 */
	public function testNoManagerIsNull(): void {
		self::assertNull($this->lookup([])->forLearner(learnerId: 'pupil-1'));
		self::assertNull($this->lookup([['id' => 'p-2', 'ncUserId' => 'pupil-2', 'managerId' => 'mentor-2']])->forLearner(learnerId: 'pupil-1'));
		self::assertNull($this->lookup([['id' => 'p-1', 'ncUserId' => 'pupil-1', 'managerId' => '']])->forLearner(learnerId: 'pupil-1'));
	}//end testNoManagerIsNull()

	/**
	 * An empty learner id is never looked up.
	 *
	 * @return void
	 */
	public function testAnEmptyLearnerIsNotLookedUp(): void {
		self::assertNull($this->lookup([['id' => 'p-1', 'ncUserId' => '', 'managerId' => 'mentor-1']])->forLearner(learnerId: ''));
		self::assertSame([], $this->reads);
	}//end testAnEmptyLearnerIsNotLookedUp()

	/**
	 * A failed read is the caller's to handle.
	 *
	 * @return void
	 */
	public function testAFailedReadThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->lookup(null)->forLearner(learnerId: 'pupil-1');
	}//end testAFailedReadThrows()
}//end class
