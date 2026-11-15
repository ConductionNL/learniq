<?php

/**
 * Learniq work group membership unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\WorkGroup
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\WorkGroup;

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real reader and membership service over a register-faithful store.
 */
class WorkGroupMembershipServiceTest extends TestCase {

	/**
	 * 2026-09-29 12:00 UTC.
	 */
	private const NOW = 1790683200;

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Lock calls in order.
	 *
	 * @var array<int, string>
	 */
	private array $lockLog = [];

	/**
	 * Users writes ran as.
	 *
	 * @var array<int, string>
	 */
	private array $ranAs = [];

	/**
	 * The service over a cohort with a set of three groups.
	 *
	 * @param string|null $until The groups' selfJoinUntil.
	 *
	 * @return array{0: WorkGroupMembershipService, 1: WorkGroupReader}
	 */
	private function services(?string $until = '2026-10-06T00:00:00+00:00'): array {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['cohort'] = [['id' => 'coh-1', 'name' => 'MV2A', 'learnerIds' => ['a', 'b', 'c', 'd', 'e']]];
		$group = static fn (string $id, string $name, array $members, string $set = 'Campagne'): array => [
			'id' => $id, 'cohortId' => 'coh-1', 'setName' => $set, 'name' => $name, 'maxMembers' => 2,
			'memberIds' => $members, 'selfJoinUntil' => $until, 'lifecycle' => 'open',
		];
		$this->store->rows['work-group'] = [
			$group('g1', 'Groep 1', ['a', 'b']),
			$group('g2', 'Groep 2', ['c']),
			$group('g3', 'Groep 3', []),
			$group('x1', 'Ander project', ['c'], 'Ander'),
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new DoesNotExistException('gone');
			}
		);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation): mixed {
				$this->ranAs[] = $user->getUID();
				return $operation();
			}
		);

		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(function (string $key): void {
			$this->lockLog[] = 'acquire:' . $key;
		});
		$locks->method('releaseLock')->willReturnCallback(function (string $key): void {
			$this->lockLog[] = 'release:' . $key;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $id): string => strtoupper($id));

		$reader = new WorkGroupReader(objects: $objects, users: $users);

		return [new WorkGroupMembershipService(objects: $objects, reader: $reader, locks: $locks, time: $time), $reader];
	}//end services()

	/**
	 * A learner of the cohort.
	 *
	 * @param string $uid The user id.
	 *
	 * @return PortalLearner
	 */
	private function learner(string $uid): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new PortalLearner(profileRef: '', ncUserId: $uid, tenantId: '', user: $user);
	}//end learner()

	/**
	 * The members per group id.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function members(): array {
		return array_column($this->store->rows['work-group'], 'memberIds', 'id');
	}//end members()

	/**
	 * A learner joins a group with a free place, under the group's lock, as
	 * themselves.
	 *
	 * @return void
	 */
	public function testALearnerJoinsAGroupWithAFreePlace(): void {
		[$service] = $this->services();

		self::assertSame(200, $service->join(learner: $this->learner('d'), groupId: 'g2')->status);
		self::assertSame(['c', 'd'], $this->members()['g2']);
		self::assertSame(['acquire:learniq-work-group-g2', 'release:learniq-work-group-g2'], $this->lockLog);
		self::assertSame(['d'], $this->ranAs);
	}//end testALearnerJoinsAGroupWithAFreePlace()

	/**
	 * A full group refuses with a reason and stays as it is.
	 *
	 * @return void
	 */
	public function testAFullGroupTakesNobodyMore(): void {
		[$service] = $this->services();
		$outcome = $service->join(learner: $this->learner('e'), groupId: 'g1');

		self::assertSame('full', $outcome->reason);
		self::assertSame(['a', 'b'], $this->members()['g1']);
	}//end testAFullGroupTakesNobodyMore()

	/**
	 * Joining another group of the same set moves the learner; a group of
	 * another set is left alone.
	 *
	 * @return void
	 */
	public function testALearnerSwitchesGroupsInOneStep(): void {
		[$service] = $this->services();
		$outcome = $service->join(learner: $this->learner('c'), groupId: 'g3');

		self::assertSame('g2', $outcome->body['left']);
		self::assertSame([], $this->members()['g2']);
		self::assertSame(['c'], $this->members()['g3']);
		self::assertSame(['c'], $this->members()['x1']);
	}//end testALearnerSwitchesGroupsInOneStep()

	/**
	 * After the sign-up date, without one, or for a stranger, nothing moves.
	 *
	 * @return void
	 */
	public function testClosedSignUpAndStrangersAreRefused(): void {
		[$late] = $this->services(until: '2026-09-28T00:00:00+00:00');
		self::assertSame('sign-up-closed', $late->join(learner: $this->learner('d'), groupId: 'g3')->reason);
		self::assertSame('sign-up-closed', $late->leave(learner: $this->learner('a'), groupId: 'g1')->reason);

		[$placed] = $this->services(until: null);
		self::assertSame('sign-up-closed', $placed->join(learner: $this->learner('d'), groupId: 'g3')->reason);

		[$service] = $this->services();
		self::assertSame('not-in-cohort', $service->join(learner: $this->learner('zz'), groupId: 'g3')->reason);
		self::assertSame('not-found', $service->join(learner: $this->learner('d'), groupId: 'nope')->reason);
		self::assertSame([], $this->members()['g3']);
	}//end testClosedSignUpAndStrangersAreRefused()

	/**
	 * A member leaves; a non-member cannot.
	 *
	 * @return void
	 */
	public function testAMemberLeaves(): void {
		[$service] = $this->services();

		self::assertSame(200, $service->leave(learner: $this->learner('a'), groupId: 'g1')->status);
		self::assertSame(['b'], $this->members()['g1']);
		self::assertSame('not-a-member', $service->leave(learner: $this->learner('d'), groupId: 'g1')->reason);
	}//end testAMemberLeaves()

	/**
	 * The overview lists the learner's sets with free places, names and
	 * which group is theirs; a learner of no cohort sees nothing.
	 *
	 * @return void
	 */
	public function testTheOverviewShowsFreePlacesAndOwnGroup(): void {
		[$service, $reader] = $this->services();
		$sets = $reader->mine(userId: 'c', isOpen: $service->isOpen(...));

		self::assertSame(['Ander', 'Campagne'], array_column($sets, 'setName'));
		$campagne = $sets[1];
		self::assertTrue($campagne['open']);
		self::assertSame([0, 1, 2], array_column($campagne['groups'], 'free'));
		self::assertSame([false, true, false], array_column($campagne['groups'], 'mine'));
		self::assertSame(['A', 'B'], $campagne['groups'][0]['members']);
		self::assertSame([], $reader->mine(userId: 'zz', isOpen: $service->isOpen(...)));
	}//end testTheOverviewShowsFreePlacesAndOwnGroup()
}//end class
