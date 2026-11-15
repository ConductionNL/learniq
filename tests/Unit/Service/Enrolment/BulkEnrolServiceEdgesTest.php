<?php

/**
 * BulkEnrolService: which earlier enrolments block a new one, and what is written.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Enrolment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Enrolment;

use OCA\Learniq\Service\Enrolment\BulkEnrolService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Edges of bulk enrolment over a mocked ObjectService.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */
#[CoversClass(BulkEnrolService::class)]
class BulkEnrolServiceEdgesTest extends TestCase {

	/**
	 * Earlier lifecycles and whether they block a new enrolment.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function earlierEnrolments(): array {
		return [
			'withdrawn' => [['lifecycle' => 'withdrawn'], false],
			'failed' => [['lifecycle' => 'failed'], false],
			'completed' => [['lifecycle' => 'completed'], false],
			'active' => [['lifecycle' => 'active'], true],
			'without lifecycle reads as pending' => [[], true],
			'unknown lifecycle' => [['lifecycle' => 'paused'], true],
		];
	}//end earlierEnrolments()

	/**
	 * Only an enrolment in a closed lifecycle lets the learner be enrolled again.
	 *
	 * @param array<string, mixed> $earlier The earlier enrolment.
	 * @param bool                 $blocks  Whether it blocks.
	 *
	 * @return void
	 */
	#[DataProvider('earlierEnrolments')]
	public function testOnlyAClosedEnrolmentLetsTheLearnerBack(array $earlier, bool $blocks): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([$earlier]);
		$objects->expects($blocks === true ? $this->never() : $this->once())->method('saveObject');

		$outcome = (new BulkEnrolService(objectService: $objects))->enrol(course: ['id' => 'c-1'], cohort: ['id' => 'g-1', 'learnerIds' => ['l-1']]);

		self::assertSame(['enrolled' => ($blocks === true ? 0 : 1), 'skipped' => ($blocks === true ? 1 : 0), 'refused' => []], $outcome);
	}//end testOnlyAClosedEnrolmentLetsTheLearnerBack()

	/**
	 * One open enrolment among closed ones still blocks.
	 *
	 * @return void
	 */
	public function testAnOpenEnrolmentAmongClosedOnesBlocks(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([['lifecycle' => 'completed'], ['lifecycle' => 'active']]);
		$objects->expects($this->never())->method('saveObject');

		$outcome = (new BulkEnrolService(objectService: $objects))->enrol(course: ['id' => 'c-1'], cohort: ['id' => 'g-1', 'learnerIds' => ['l-1']]);

		self::assertSame(1, $outcome['skipped']);
	}//end testAnOpenEnrolmentAmongClosedOnesBlocks()

	/**
	 * The lookup is per course and learner in the app's register, without RBAC.
	 *
	 * @return void
	 */
	public function testTheLookupAsksForThisCourseAndLearnerOnly(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('findAll')
			->with(
				['filters' => ['register' => 'learniq', 'schema' => 'enrolment', 'courseId' => '7', 'learnerId' => 'l-1']],
				false
			)
			->willReturn([]);
		$saved = [];
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$saved): ObjectEntity {
				$saved = [$object, $register, $schema];

				return OrEntityFactory::make($object, 'enrolment');
			}
		);

		$outcome = (new BulkEnrolService(objectService: $objects))->enrol(course: ['id' => 7], cohort: ['id' => 9, 'learnerIds' => ['l-1']]);

		self::assertSame(['enrolled' => 1, 'skipped' => 0, 'refused' => []], $outcome);
		self::assertSame(
			[['learnerId' => 'l-1', 'courseId' => '7', 'cohortId' => '9', 'source' => 'bulk', 'tenant_id' => ''], 'learniq', 'enrolment'],
			$saved
		);
	}//end testTheLookupAsksForThisCourseAndLearnerOnly()

	/**
	 * A group without usable learner ids enrols nobody and asks nothing.
	 *
	 * @return void
	 */
	public function testAGroupWithoutUsableIdsEnrolsNobody(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('findAll');
		$objects->expects($this->never())->method('saveObject');
		$service = new BulkEnrolService(objectService: $objects);

		self::assertSame(['enrolled' => 0, 'skipped' => 0, 'refused' => []], $service->enrol(course: ['id' => 'c-1'], cohort: ['id' => 'g-1']));
		self::assertSame(
			['enrolled' => 0, 'skipped' => 0, 'refused' => []],
			$service->enrol(course: ['id' => 'c-1'], cohort: ['id' => 'g-1', 'learnerIds' => [42, null, '', ['l-1']]])
		);
	}//end testAGroupWithoutUsableIdsEnrolsNobody()

	/**
	 * Every refused learner is named, in the group's order, and the rest still enrol.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsNamedInOrder(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([]);
		$objects->expects($this->exactly(3))->method('saveObject')->willReturnCallback(
			static function (array $object): ObjectEntity {
				if ($object['learnerId'] !== 'l-2') {
					throw new RuntimeException('refused');
				}

				return OrEntityFactory::make($object, 'enrolment');
			}
		);

		$outcome = (new BulkEnrolService(objectService: $objects))->enrol(course: ['id' => 'c-1'], cohort: ['id' => 'g-1', 'learnerIds' => ['l-3', 'l-2', 'l-1']]);

		self::assertSame(['enrolled' => 1, 'skipped' => 0, 'refused' => ['l-3', 'l-1']], $outcome);
	}//end testEveryRefusalIsNamedInOrder()
}//end class
