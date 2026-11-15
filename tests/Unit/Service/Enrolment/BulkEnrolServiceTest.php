<?php

/**
 * Learniq BulkEnrolService and BulkEnrolController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Enrolment
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
 * @spec openspec/specs/enrolment/spec.md#requirement-bulk-enrolment-via-cohort-role-department-or-csv
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Enrolment;

use OCA\Learniq\Controller\BulkEnrolController;
use OCA\Learniq\Service\Enrolment\BulkEnrolService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BulkEnrolService over a mocked ObjectService.
 */
class BulkEnrolServiceTest extends TestCase {

	private const COURSE = 'c0000000-0000-4000-8000-000000000001';
	private const COHORT = 'b0000000-0000-4000-8000-000000000001';
	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * The enrolments created.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	/**
	 * An ObjectService where lrn-2 has an open enrolment, lrn-3 a completed
	 * one, and creating one for lrn-4 is refused.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				return match ($config['filters']['learnerId'] ?? '') {
					'lrn-2' => [OrEntityFactory::make(['learnerId' => 'lrn-2', 'lifecycle' => 'active'], 'enrolment')],
					'lrn-3' => [['learnerId' => 'lrn-3', 'lifecycle' => 'completed']],
					default => [],
				};
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) {
				if ($object['learnerId'] === 'lrn-4') {
					throw new RuntimeException('prerequisite not met');
				}

				$this->created[] = $object;

				return OrEntityFactory::make($object, 'enrolment');
			}
		);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) {
				return match ((string)$id) {
					self::COURSE => OrEntityFactory::make(['id' => self::COURSE, 'tenant_id' => self::TENANT], 'course'),
					self::COHORT => OrEntityFactory::make(['id' => self::COHORT, 'learnerIds' => ['lrn-1', 'lrn-2', 'lrn-3', 'lrn-4', 'lrn-1', '']], 'cohort'),
					default => throw new DoesNotExistException('gone'),
				};
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The group's learners are enrolled once each, open enrolments are
	 * skipped and a refusal is named without stopping the rest.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-bulk-enrol-a-selected-group-of-learners
	 */
	public function testAGroupIsEnrolledSkippingOpenEnrolments(): void {
		$outcome = (new BulkEnrolService(objectService: $this->objects()))->enrol(
			course: ['id' => self::COURSE, 'tenant_id' => self::TENANT],
			cohort: ['id' => self::COHORT, 'learnerIds' => ['lrn-1', 'lrn-2', 'lrn-3', 'lrn-4', 'lrn-1', '']]
		);

		self::assertSame(['enrolled' => 2, 'skipped' => 1, 'refused' => ['lrn-4']], $outcome);
		self::assertSame(['lrn-1', 'lrn-3'], array_column($this->created, 'learnerId'));
		self::assertSame(
			['learnerId' => 'lrn-1', 'courseId' => self::COURSE, 'cohortId' => self::COHORT, 'source' => 'bulk', 'tenant_id' => self::TENANT],
			$this->created[0]
		);
	}//end testAGroupIsEnrolledSkippingOpenEnrolments()

	/**
	 * The endpoint reads the course and group as the caller and answers the outcome.
	 *
	 * @return void
	 */
	public function testTheEndpointEnrolsAndRefusesUnknownRecords(): void {
		$make = function (array $params, bool $signedIn = true): BulkEnrolController {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
			$objects = $this->objects();

			return new BulkEnrolController(
				request: $request,
				userSession: $session,
				objectService: $objects,
				bulkEnrol: new BulkEnrolService(objectService: $objects)
			);
		};

		self::assertSame(2, $make(['courseId' => self::COURSE, 'cohortId' => self::COHORT])->enrol()->getData()['enrolled']);
		self::assertSame(404, $make(['courseId' => self::COURSE, 'cohortId' => 'nope'])->enrol()->getStatus());
		self::assertSame(401, $make(['courseId' => self::COURSE, 'cohortId' => self::COHORT], false)->enrol()->getStatus());
	}//end testTheEndpointEnrolsAndRefusesUnknownRecords()
}//end class
