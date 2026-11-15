<?php

/**
 * Learniq work group reader unit tests.
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

use OCA\Learniq\Service\WorkGroup\WorkGroupReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reader's queries to OpenRegister and the learner's overview it builds from them.
 *
 * @covers \OCA\Learniq\Service\WorkGroup\WorkGroupReader
 * @uses   \OCA\Learniq\Support\FindOrNull
 */
class WorkGroupReaderTest extends TestCase {

	private ObjectService&MockObject $objects;

	private IUserManager&MockObject $users;

	/**
	 * Cohorts by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cohorts = [];

	/**
	 * Every find asked, as "schema:id".
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) {
				$this->finds[] = $schema . ':' . $id;
				self::assertSame('learniq', $register);
				self::assertFalse($_rbac, 'reads are made as the system');
				self::assertFalse($_multitenancy);
				self::assertFalse($_render);
				if ($schema === 'cohort' && isset($this->cohorts[$id]) === true) {
					return OrEntityFactory::make($this->cohorts[$id], 'cohort');
				}

				throw new DoesNotExistException('gone');
			}
		);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ['ana' => 'Ana de Vries', 'bo' => 'Bo Smit'][$uid] ?? null
		);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @return WorkGroupReader
	 */
	private function reader(): WorkGroupReader {
		return new WorkGroupReader(objects: $this->objects, users: $this->users);
	}//end reader()

	public function testAGroupWithoutAnIdIsNotLookedUp(): void {
		self::assertNull($this->reader()->group(''));
		self::assertSame([], $this->finds);
	}//end testAGroupWithoutAnIdIsNotLookedUp()

	public function testAMissingGroupIsNull(): void {
		self::assertNull($this->reader()->group('g-gone'));
		self::assertSame(['work-group:g-gone'], $this->finds);
	}//end testAMissingGroupIsNull()

	public function testAGroupIsReadAsARowWithItsId(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('find')->willReturn(OrEntityFactory::make(['name' => 'Groep A'], 'work-group', 'learniq', 'g-1'));

		$group = (new WorkGroupReader(objects: $objects, users: $this->users))->group('g-1');

		self::assertSame('g-1', $group['id']);
		self::assertSame('Groep A', $group['name']);
	}//end testAGroupIsReadAsARowWithItsId()

	public function testACohortsLearnersAreItsStringIds(): void {
		$this->cohorts['c-1'] = ['id' => 'c-1', 'learnerIds' => ['ana', 7, null, 'bo']];

		self::assertSame(['ana', 'bo'], $this->reader()->cohortLearners('c-1'));
		self::assertSame([], $this->reader()->cohortLearners('c-gone'));
		self::assertSame([], $this->reader()->cohortLearners(''));
	}//end testACohortsLearnersAreItsStringIds()

	public function testASetIsQueriedByCohortAndSetAndSortedByName(): void {
		$this->objects->expects(self::once())->method('findAll')->with(
			['filters' => ['register' => 'learniq', 'schema' => 'work-group', 'cohortId' => 'c-1', 'setName' => 'Project'], 'limit' => 2000],
			false
		)->willReturn([
			OrEntityFactory::make(['id' => 'g-10', 'name' => 'Groep 10'], 'work-group'),
			['@self' => ['id' => 'g-2'], 'name' => 'groep 2'],
			'not a row',
			['name' => 'Groep 1'],
		]);

		$set = $this->reader()->set('c-1', 'Project');

		self::assertSame(['Groep 1', 'groep 2', 'Groep 10'], array_column($set, 'name'), 'natural, case-insensitive order');
		self::assertSame(['', 'g-2', 'g-10'], array_column($set, 'id'));
	}//end testASetIsQueriedByCohortAndSetAndSortedByName()

	public function testTheOverviewHoldsOnlyTheLearnersCohortsPerSet(): void {
		$this->cohorts['c-1'] = ['id' => 'c-1', 'name' => 'Klas 3A', 'learnerIds' => ['ana', 'bo']];
		$this->cohorts['c-2'] = ['id' => 'c-2', 'name' => 'Klas 4B', 'learnerIds' => ['cas']];
		$this->objects->expects(self::once())->method('findAll')->with(
			['filters' => ['register' => 'learniq', 'schema' => 'work-group'], 'limit' => 2000],
			false
		)->willReturn([
			['id' => 'g-b', 'name' => 'B', 'cohortId' => 'c-1', 'setName' => 'Project', 'maxMembers' => 2, 'memberIds' => ['bo', 'ana', 'cas'], 'selfJoinUntil' => '2026-10-01'],
			['id' => 'g-a', 'name' => 'A', 'cohortId' => 'c-1', 'setName' => 'Project', 'maxMembers' => 3, 'memberIds' => ['bo', 5]],
			['id' => 'g-x', 'name' => 'X', 'cohortId' => 'c-2', 'setName' => 'Project', 'maxMembers' => 3, 'memberIds' => []],
			['id' => 'g-p', 'name' => 'P', 'cohortId' => 'c-1', 'setName' => 'Practicum'],
		]);
		$asked = [];
		$isOpen = static function (array $group) use (&$asked): bool {
			$asked[] = $group['id'];
			return $group['setName'] === 'Project';
		};

		$overview = $this->reader()->mine('ana', $isOpen);

		self::assertSame(['g-a', 'g-p'], $asked, 'open is asked once per set, of its first group');
		self::assertSame(['cohort:c-1', 'cohort:c-2'], $this->finds, 'each cohort is read once');
		self::assertCount(2, $overview);

		[$project, $practicum] = $overview;
		self::assertSame('Klas 3A', $project['cohortName']);
		self::assertSame('Project', $project['setName']);
		self::assertNull($project['selfJoinUntil'], 'the set takes its first group\'s deadline');
		self::assertTrue($project['open']);
		self::assertFalse($practicum['open']);

		self::assertSame(
			['id' => 'g-a', 'name' => 'A', 'maxMembers' => 3, 'free' => 2, 'members' => ['Bo Smit'], 'mine' => false, 'memberIds' => []],
			$project['groups'][0],
			'a group the learner is not in shows names only'
		);
		self::assertSame(
			['id' => 'g-b', 'name' => 'B', 'maxMembers' => 2, 'free' => 0, 'members' => ['Bo Smit', 'Ana de Vries', 'cas'], 'mine' => true, 'memberIds' => ['bo', 'ana', 'cas']],
			$project['groups'][1],
			'an overfull group has no free places, and an unknown account shows its uid'
		);
		self::assertSame(['id' => 'g-p', 'name' => 'P', 'maxMembers' => 0, 'free' => 0, 'members' => [], 'mine' => false, 'memberIds' => []], $practicum['groups'][0]);
	}//end testTheOverviewHoldsOnlyTheLearnersCohortsPerSet()
}//end class
