<?php

/**
 * Learniq RejectionResubmitGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\RejectionResubmitGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for RejectionResubmitGuard::check() — the ExchangeRejection
 * `corrected → resubmitted` transition.
 */
class RejectionResubmitGuardTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];

	}//end setUp()

	/**
	 * Build a guard whose user/group managers report the given group
	 * membership for a known 'actor-1' user, and whose ObjectService resolves
	 * the originating job + records saveObject() calls.
	 *
	 * @param string[] $groups Group IDs 'actor-1' belongs to.
	 * @param array<string,mixed>|null $originalJob The originating DataExchangeJob row findAll() returns, or null.
	 * @param string|null $newJobId UUID to return for the new DataExchangeJob save, or null to
	 *                              simulate a save that yields no usable id.
	 *
	 * @return RejectionResubmitGuard
	 */
	private function makeGuard(array $groups, ?array $originalJob, ?string $newJobId = 'new-job-1'): RejectionResubmitGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($user): ?IUser {
				return $uid === 'actor-1' ? $user : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($originalJob): array {
				if (($config['filters']['schema'] ?? '') !== 'data-exchange-job') {
					return [];
				}

				if ($originalJob === null) {
					return [];
				}

				return OrEntityFactory::makeMany([$originalJob], 'data-exchange-job');
			}
		);

		// OpenRegister's saveObject() is saveObject($object, $extend, $register, $schema, ...)
		// — the PAYLOAD IS FIRST — and returns a non-nullable ObjectEntity.
		// willReturnCallback() hands the closure the mock's arguments
		// POSITIONALLY, so the closure must mirror that order.
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($newJobId): ObjectEntity {
				$this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

				if ($newJobId === null) {
					// saveObject() cannot return null — its declared return type
					// is a non-nullable ObjectEntity. The in-band failure the
					// guard actually has to survive is a saved entity that
					// carries no usable id.
					return OrEntityFactory::make($object, 'data-exchange-job', 'learniq', null);
				}

				return OrEntityFactory::make(array_merge($object, ['id' => $newJobId]), 'data-exchange-job');
			}
		);

		return new RejectionResubmitGuard($objectService, $groupManager, $userManager, new NullLogger());
	}//end makeGuard()

	/**
	 * The rejection as the guard sees it on resubmit.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function rejection(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'rej-1',
				'sourceKind' => 'learner-profile',
				'learnerProfileId' => 'lp-1',
				'dataExchangeJobId' => 'job-orig',
				'tenant_id' => 'tenant-a',
				'status' => 'resubmitted',
			],
			$overrides
		);
	}//end rejection()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		self::assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([], null));

	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * A coordinator resubmitting a corrected rejection is allowed, and the guard
	 * creates nothing: the scoped job is RejectionResubmissionAction's write
	 * (learniq#983), see tests/Unit/Lifecycle/Action/RejectionResubmissionActionTest.php.
	 *
	 * @return void
	 */
	public function testCoordinatorResubmitIsAllowedWithoutWriting(): void {
		$guard = $this->makeGuard(
			['coordinators'],
			['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']
		);

		self::assertTrue($guard->check($this->rejection(), 'resubmit', 'actor-1')->isAllowed());
		self::assertCount(0, $this->savedObjects);

	}//end testCoordinatorResubmitIsAllowedWithoutWriting()

	/**
	 * An admin resubmitting is also allowed.
	 *
	 * @return void
	 */
	public function testAdminResubmitIsAllowed(): void {
		$guard = $this->makeGuard(
			['admin'],
			['id' => 'job-orig', 'target' => 'leerplicht', 'mappingProfileId' => null]
		);

		$object = $this->rejection(['sourceKind' => 'attendance-flag', 'attendanceFlagId' => 'flag-1', 'learnerProfileId' => null]);

		self::assertTrue($guard->check($object, 'resubmit', 'actor-1')->isAllowed());

	}//end testAdminResubmitIsAllowed()

	/**
	 * A learner (no privileged group) is denied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-non-authorised-user-cannot-resubmit-or-waive
	 */
	public function testUnauthorisedActorIsDenied(): void {
		$guard = $this->makeGuard(
			[],
			['id' => 'job-orig', 'target' => 'bron-rod', 'mappingProfileId' => 'profile-1']
		);

		$result = $guard->check($this->rejection(), 'resubmit', 'actor-1');

		self::assertFalse($result->isAllowed());
		self::assertNotSame('', (string)$result->getMessage());

	}//end testUnauthorisedActorIsDenied()

	/**
	 * No session user is denied.
	 *
	 * @return void
	 */
	public function testNoActorIsDenied(): void {
		$guard = $this->makeGuard(['coordinators'], ['id' => 'job-orig', 'target' => 'bron-rod']);

		self::assertFalse($guard->check($this->rejection(), 'resubmit', '')->isAllowed());

	}//end testNoActorIsDenied()

	/**
	 * An unresolvable originating DataExchangeJob denies the transition.
	 *
	 * @return void
	 */
	public function testUnresolvableOriginalJobIsDenied(): void {
		$guard = $this->makeGuard(['coordinators'], null);

		self::assertFalse($guard->check($this->rejection(['dataExchangeJobId' => 'job-missing']), 'resubmit', 'actor-1')->isAllowed());

	}//end testUnresolvableOriginalJobIsDenied()

	/**
	 * A rejection without its source object id denies the transition.
	 *
	 * @return void
	 */
	public function testMissingSourceObjectIdIsDenied(): void {
		$guard = $this->makeGuard(['coordinators'], ['id' => 'job-orig', 'target' => 'bron-rod']);

		self::assertFalse($guard->check($this->rejection(['learnerProfileId' => '']), 'resubmit', 'actor-1')->isAllowed());

	}//end testMissingSourceObjectIdIsDenied()

	/**
	 * An unsupported sourceKind on the rejection denies the transition.
	 *
	 * @return void
	 */
	public function testUnsupportedSourceKindIsDenied(): void {
		$guard = $this->makeGuard(['coordinators'], ['id' => 'job-orig', 'target' => 'bron-rod']);

		self::assertFalse($guard->check($this->rejection(['sourceKind' => 'cohort']), 'resubmit', 'actor-1')->isAllowed());

	}//end testUnsupportedSourceKindIsDenied()
}//end class
