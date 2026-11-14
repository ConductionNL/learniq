<?php

/**
 * Learniq work group membership edge-case unit tests.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * The open/closed rule, the overview and the repeated join, over the real reader.
 *
 * @covers \OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService
 * @uses   \OCA\Learniq\Service\WorkGroup\WorkGroupReader
 * @uses   \OCA\Learniq\Service\Portal\PortalLearner
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 * @uses   \OCA\Learniq\Support\FindOrNull
 */
class WorkGroupMembershipEdgeCasesTest extends TestCase {

	/**
	 * 2026-09-29 12:00 UTC.
	 */
	private const NOW = 1790683200;

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Users writes ran as.
	 *
	 * @var array<int, string>
	 */
	private array $ranAs = [];

	/**
	 * Lock calls in order.
	 *
	 * @var array<int, string>
	 */
	private array $lockLog = [];

	/**
	 * The service over the rows in $this->rows.
	 *
	 * @return array{0: WorkGroupMembershipService, 1: WorkGroupReader}
	 */
	private function services(): array {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = []): array => array_values(array_filter(
				$this->rows['work-group'] ?? [],
				static fn (array $row): bool => ($config['filters']['cohortId'] ?? $row['cohortId']) === $row['cohortId']
					&& ($config['filters']['setName'] ?? $row['setName']) === $row['setName']
			))
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				foreach (($this->rows[(string)$schema] ?? []) as $row) {
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
	 * A learner.
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
	 * A group of cohort coh-1.
	 *
	 * @param string             $id      The uuid.
	 * @param list<string>       $members The member ids.
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function group(string $id, array $members, array $extra = []): array {
		return array_merge([
			'id' => $id, 'cohortId' => 'coh-1', 'setName' => 'Set', 'name' => 'Groep ' . $id, 'maxMembers' => 3,
			'memberIds' => $members, 'selfJoinUntil' => '2026-10-06T00:00:00+00:00', 'lifecycle' => 'open',
		], $extra);
	}//end group()

	/**
	 * Only an open lifecycle with a readable, unpassed sign-up date is open; the
	 * date itself still counts.
	 *
	 * @return void
	 */
	public function testIsOpenNeedsAnOpenLifecycleAndAReadableFutureDate(): void {
		[$service] = $this->services();

		self::assertTrue($service->isOpen(group: ['selfJoinUntil' => '2026-10-06T00:00:00+00:00']));
		self::assertTrue($service->isOpen(group: ['selfJoinUntil' => '2026-09-29T12:00:00+00:00']));
		self::assertFalse($service->isOpen(group: ['selfJoinUntil' => '2026-09-29T11:59:59+00:00']));
		self::assertFalse($service->isOpen(group: ['lifecycle' => 'closed', 'selfJoinUntil' => '2026-10-06T00:00:00+00:00']));
		self::assertFalse($service->isOpen(group: ['selfJoinUntil' => 'not a date']));
		self::assertFalse($service->isOpen(group: ['selfJoinUntil' => '']));
		self::assertFalse($service->isOpen(group: ['selfJoinUntil' => 20261006]));
	}//end testIsOpenNeedsAnOpenLifecycleAndAReadableFutureDate()

	/**
	 * A closed lifecycle refuses a join before any write.
	 *
	 * @return void
	 */
	public function testAClosedLifecycleRefusesTheJoin(): void {
		$this->rows = [
			'cohort' => [['id' => 'coh-1', 'name' => 'MV2A', 'learnerIds' => ['a']]],
			'work-group' => [self::group('g1', [], ['lifecycle' => 'closed'])],
		];
		[$service] = $this->services();

		$outcome = $service->join(learner: $this->learner('a'), groupId: 'g1');

		self::assertSame(422, $outcome->status);
		self::assertSame(['error' => 'sign_up_closed'], $outcome->body);
		self::assertSame([], $this->ranAs);
	}//end testAClosedLifecycleRefusesTheJoin()

	/**
	 * Joining a group the learner is already in answers 200 without writing,
	 * and still releases the lock.
	 *
	 * @return void
	 */
	public function testJoiningTheOwnGroupAgainWritesNothing(): void {
		$this->rows = [
			'cohort' => [['id' => 'coh-1', 'name' => 'MV2A', 'learnerIds' => ['a']]],
			'work-group' => [self::group('g1', ['a'])],
		];
		[$service] = $this->services();

		$outcome = $service->join(learner: $this->learner('a'), groupId: 'g1');

		self::assertSame(200, $outcome->status);
		self::assertSame(['groupId' => 'g1', 'left' => null], $outcome->body);
		self::assertSame([], $this->ranAs);
		self::assertSame(['acquire:learniq-work-group-g1', 'release:learniq-work-group-g1'], $this->lockLog);
	}//end testJoiningTheOwnGroupAgainWritesNothing()

	/**
	 * A group without a cohort has no learners, so nobody may join it.
	 *
	 * @return void
	 */
	public function testAGroupWithoutACohortRefusesEveryone(): void {
		$this->rows = [
			'cohort' => [['id' => 'coh-1', 'name' => 'MV2A', 'learnerIds' => ['a']]],
			'work-group' => [self::group('g1', [], ['cohortId' => ''])],
		];
		[$service, $reader] = $this->services();

		self::assertSame([], $reader->cohortLearners(cohortId: ''));
		$outcome = $service->join(learner: $this->learner('a'), groupId: 'g1');
		self::assertSame(403, $outcome->status);
		self::assertSame('not-in-cohort', $outcome->reason);
	}//end testAGroupWithoutACohortRefusesEveryone()

	/**
	 * The service's overview wraps the reader's sets in a 200, with the open
	 * flag from the service's own rule.
	 *
	 * @return void
	 */
	public function testTheOverviewIsA200WithTheLearnersSets(): void {
		$this->rows = [
			'cohort' => [['id' => 'coh-1', 'name' => 'MV2A', 'learnerIds' => ['a', 'b']]],
			'work-group' => [
				self::group('g1', ['a', 'b'], ['maxMembers' => 2, 'lifecycle' => 'closed']),
			],
		];
		[$service] = $this->services();

		$outcome = $service->mine(learner: $this->learner('b'));

		self::assertSame(200, $outcome->status);
		$sets = $outcome->body['sets'];
		self::assertCount(1, $sets);
		self::assertSame('MV2A', $sets[0]['cohortName']);
		self::assertFalse($sets[0]['open']);
		self::assertSame(0, $sets[0]['groups'][0]['free']);
		self::assertSame(['A', 'B'], $sets[0]['groups'][0]['members']);
		self::assertSame(['a', 'b'], $sets[0]['groups'][0]['memberIds']);
	}//end testTheOverviewIsA200WithTheLearnersSets()
}//end class
