<?php

/**
 * MaterialImportTarget and CommercialCourseCheck unit tests.
 *
 * Open material lands only in a lesson or course of the caller's tenant, and
 * a corporate course or one with a compulsory course fee counts as commercial,
 * so NC material is not allowed there.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\OpenMaterial\CommercialCourseCheck;
use OCA\Learniq\Service\OpenMaterial\MaterialImportTarget;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MaterialImportTargetTest extends TestCase {

	private CallerTenantResolver&MockObject $tenants;

	private ObjectService&MockObject $objectService;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->tenants       = $this->createMock(CallerTenantResolver::class);
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * The target resolver over the given rows (id to row) and fee items.
	 *
	 * @param array<string, array<string, mixed>> $rows The rows the caller owns.
	 * @param array<int, array<string, mixed>>    $fees The fee items.
	 *
	 * @return MaterialImportTarget The resolver.
	 */
	private function target(array $rows, array $fees): MaterialImportTarget {
		$this->tenants->method('findOwned')->willReturnCallback(static fn (IUser $user, string $id): ?array => ($rows[$id] ?? null));
		$this->objectService->method('findAll')->willReturn($fees);

		return new MaterialImportTarget($this->tenants, new CommercialCourseCheck($this->objectService));
	}//end target()

	public function testALessonResolvesToItsCourse(): void {
		$target = $this->target(['les-7' => ['courseId' => 'c-1', 'tenant_id' => 't-1'], 'c-1' => ['id' => 'c-1', 'level' => 'vo', 'tenant_id' => 't-1']], []);

		$resolved = $target->resolve(user: $this->createMock(IUser::class), request: ['lessonId' => 'les-7']);

		$this->assertSame(expected: ['fields' => ['courseId' => 'c-1', 'tenant_id' => 't-1', 'lessonId' => 'les-7'], 'commercial' => false], actual: $resolved);
	}//end testALessonResolvesToItsCourse()

	public function testACourseOfAnotherTenantIsNotFound(): void {
		$this->expectException(MaterialImportRefusedException::class);

		$this->target([], [])->resolve(user: $this->createMock(IUser::class), request: ['courseId' => 'someone-elses']);
	}//end testACourseOfAnotherTenantIsNotFound()

	public function testACorporateCourseIsCommercial(): void {
		$target = $this->target(['c-1' => ['id' => 'c-1', 'level' => 'corporate']], []);

		$this->assertTrue($target->resolve(user: $this->createMock(IUser::class), request: ['courseId' => 'c-1'])['commercial']);
	}//end testACorporateCourseIsCommercial()

	public function testACompulsoryCourseFeeMakesItCommercial(): void {
		$target = $this->target(['c-1' => ['id' => 'c-1', 'level' => 'mbo']], [['kind' => 'course-enrolment', 'amount' => 250, 'voluntary' => false]]);

		$this->assertTrue($target->resolve(user: $this->createMock(IUser::class), request: ['courseId' => 'c-1'])['commercial']);
	}//end testACompulsoryCourseFeeMakesItCommercial()

	public function testAVoluntaryContributionDoesNot(): void {
		$target = $this->target(['c-1' => ['id' => 'c-1', 'level' => 'vo']], [['kind' => 'course-enrolment', 'amount' => 25, 'voluntary' => true], ['kind' => 'school-trip', 'amount' => 80]]);

		$this->assertFalse($target->resolve(user: $this->createMock(IUser::class), request: ['courseId' => 'c-1'])['commercial']);
	}//end testAVoluntaryContributionDoesNot()
}//end class
