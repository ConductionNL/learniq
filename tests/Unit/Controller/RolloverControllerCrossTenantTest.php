<?php

/**
 * Learniq RolloverController cross-tenant lookup tests.
 *
 * The action matrix answers "may this user plan a rollover", never "does this
 * plan belong to this user's school". preview() and proposeMapping() must scope
 * their reads, and preview() its write, to the caller's own tenant.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RolloverController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\RolloverService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Tests that a rollover planner only reaches their own tenant's plans and cohorts.
 */
class RolloverControllerCrossTenantTest extends TestCase {
	private const CALLER_TENANT = 'tenant-a';
	private const OTHER_TENANT = 'tenant-b';

	/**
	 * A plan of another tenant returns 404 and is neither previewed nor saved.
	 *
	 * @return void
	 */
	public function testPreviewOfAnotherTenantsPlanIsNotFoundAndWritesNothing(): void {
		$objectService = $this->planStore(planTenant: self::OTHER_TENANT);
		$objectService->expects(self::never())->method('saveObject');

		$rolloverService = $this->createMock(RolloverService::class);
		$rolloverService->expects(self::never())->method('preview');

		$response = $this->controller(objectService: $objectService, rolloverService: $rolloverService)->preview(planId: 'plan-b');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Plan not found', ((array)$response->getData())['error'] ?? null);
	}//end testPreviewOfAnotherTenantsPlanIsNotFoundAndWritesNothing()

	/**
	 * Control: the caller's own plan is previewed and saved as before.
	 *
	 * @return void
	 */
	public function testPreviewOfTheCallersOwnPlanStillWorks(): void {
		$objectService = $this->planStore(planTenant: self::CALLER_TENANT);
		$objectService->expects(self::once())->method('saveObject')->with(
			self::anything(),
			self::anything(),
			'learniq',
			'rollover-plan'
		);

		$rolloverService = $this->createMock(RolloverService::class);
		$rolloverService->expects(self::once())->method('preview')->willReturn(['blocked' => false]);

		$response = $this->controller(objectService: $objectService, rolloverService: $rolloverService)->preview(planId: 'plan-a');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPreviewOfTheCallersOwnPlanStillWorks()

	/**
	 * A plan deleted between the read and the save answers 404, not a 500.
	 *
	 * @return void
	 */
	public function testPreviewOfAPlanDeletedBeforeTheSaveIsNotFound(): void {
		$objectService = $this->planStore(planTenant: self::CALLER_TENANT);
		$objectService->method('saveObject')->willThrowException(new DoesNotExistException('gone'));

		$rolloverService = $this->createMock(RolloverService::class);
		$rolloverService->method('preview')->willReturn(['blocked' => false]);

		$response = $this->controller(objectService: $objectService, rolloverService: $rolloverService)->preview(planId: 'plan-a');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPreviewOfAPlanDeletedBeforeTheSaveIsNotFound()

	/**
	 * Two tenants share an academic year: only the caller's cohorts are proposed.
	 *
	 * @return void
	 */
	public function testProposeMappingOnlySeesTheCallersOwnCohorts(): void {
		$cohorts = [
			['id' => 'cohort-a', 'name' => '2A', 'academicYear' => '2025-2026', 'tenant_id' => self::CALLER_TENANT],
			['id' => 'cohort-b', 'name' => '2B', 'academicYear' => '2025-2026', 'tenant_id' => self::OTHER_TENANT],
		];

		$objectService = $this->createMock(ObjectService::class);
		// Emulate OpenRegister: every filter key narrows the result set.
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($cohorts): array {
				$filters = ($config['filters'] ?? []);
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						$cohorts,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);

		$received = [];
		$rolloverService = $this->createMock(RolloverService::class);
		$rolloverService->method('proposeDefaultMapping')->willReturnCallback(
			static function (array $fromCohorts) use (&$received): array {
				$received = $fromCohorts;
				return [];
			}
		);

		$response = $this->controller(objectService: $objectService, rolloverService: $rolloverService)->proposeMapping(fromAcademicYear: '2025-2026');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['cohort-a'], array_column($received, 'id'));
	}//end testProposeMappingOnlySeesTheCallersOwnCohorts()

	/**
	 * An ObjectService whose find() returns one plan of the given tenant.
	 *
	 * @param string $planTenant Tenant the stored plan belongs to.
	 *
	 * @return ObjectService&MockObject
	 */
	private function planStore(string $planTenant): ObjectService&MockObject {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(
				[
					'id' => 'plan-x',
					'fromAcademicYear' => '2025-2026',
					'toAcademicYear' => '2026-2027',
					'lifecycle' => 'draft',
					'tenant_id' => $planTenant,
				],
				'rollover-plan'
			)
		);

		return $objectService;
	}//end planStore()

	/**
	 * Build the controller for a caller bound to CALLER_TENANT.
	 *
	 * @param ObjectService $objectService OR double.
	 * @param RolloverService $rolloverService Rollover logic double.
	 *
	 * @return RolloverController
	 */
	private function controller(ObjectService $objectService, RolloverService $rolloverService): RolloverController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('planner-a');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($key === 'tenant_id' ? self::CALLER_TENANT : $default)
		);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($key === 'instanceid' ? 'instance-x' : $default)
		);

		$class = new ReflectionClass(RolloverController::class);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[$parameter->getName()] = match ($type->getName()) {
				IUserSession::class => $userSession,
				ObjectService::class => $objectService,
				RolloverService::class => $rolloverService,
				CallerTenantResolver::class => new CallerTenantResolver($config, $objectService),
				// requireAction() returns void on success: the authorised case.
				ActionAuthService::class => $this->createMock(ActionAuthService::class),
				default => $this->createStub($type->getName()),
			};
		}

		return $class->newInstanceArgs($arguments);
	}//end controller()
}//end class
