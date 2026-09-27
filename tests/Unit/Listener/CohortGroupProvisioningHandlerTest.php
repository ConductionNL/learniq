<?php

/**
 * Learniq CohortGroupProvisioningHandler unit tests.
 *
 * Covers: Cohort activate -> NC group provisioning (idempotent), member
 * add for teacherIds/learnerIds, ncGroupId write-back, and Enrolment
 * activate/withdraw -> group membership sync (including the not-yet-
 * provisioned no-op and the unresolvable-user-id skip).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Listener\CohortGroupProvisioningHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\EventDispatcher\Event;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CohortGroupProvisioningHandler::handle().
 */
class CohortGroupProvisioningHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * IDs added to a group via IGroup::addUser(), keyed by group id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $added = [];

	/**
	 * IDs removed from a group via IGroup::removeUser(), keyed by group id.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $removed = [];

	/**
	 * Group ids created via IGroupManager::createGroup() in this test.
	 *
	 * @var array<int, string>
	 */
	private array $createdGroups = [];

	/**
	 * Reset capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];
		$this->added = [];
		$this->removed = [];
		$this->createdGroups = [];

	}//end setUp()

	/**
	 * Build a fake IUser for a given uid — resolves via IUserManager::get().
	 *
	 * @param string $uid Nextcloud user id.
	 *
	 * @return IUser
	 */
	private function makeUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;

	}//end makeUser()

	/**
	 * Build a fake IGroup that records addUser()/removeUser() calls into the
	 * test's capture buffers.
	 *
	 * @param string $gid Group id.
	 *
	 * @return IGroup
	 */
	private function makeGroup(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('addUser')->willReturnCallback(function (IUser $user) use ($gid): void {
			$this->added[$gid][] = $user->getUID();
		});
		$group->method('removeUser')->willReturnCallback(function (IUser $user) use ($gid): void {
			$this->removed[$gid][] = $user->getUID();
		});

		return $group;

	}//end makeGroup()

	/**
	 * Build a handler with stubbed collaborators.
	 *
	 * @param array<string,mixed>|null $foundCohort What ObjectService::findAll() returns for a
	 *                                               Cohort lookup by id (used by the Enrolment
	 *                                               sync path). Null simulates "not found."
	 * @param array<string,IGroup> $existingGroups Groups that already exist, keyed by gid,
	 *                                              resolved by IGroupManager::groupExists()/get().
	 * @param array<string,bool> $unresolvableUsers Set of user ids that IUserManager::get()
	 *                                               returns null for.
	 *
	 * @return CohortGroupProvisioningHandler
	 */
	private function makeHandler(
		?array $foundCohort = null,
		array $existingGroups = [],
		array $unresolvableUsers = []
	): CohortGroupProvisioningHandler {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $query) use ($foundCohort) {
				if ($foundCohort === null) {
					return [];
				}
				return [$foundCohort];
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			fn (string $gid) => array_key_exists($gid, $existingGroups)
		);
		$groupManager->method('get')->willReturnCallback(
			fn (string $gid) => $existingGroups[$gid] ?? null
		);
		$groupManager->method('createGroup')->willReturnCallback(
			function (string $gid) {
				$this->createdGroups[] = $gid;
				return $this->makeGroup(gid: $gid);
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid) use ($unresolvableUsers) {
				if (isset($unresolvableUsers[$uid]) === true) {
					return null;
				}
				return $this->makeUser(uid: $uid);
			}
		);

		return new CohortGroupProvisioningHandler($objectService, $groupManager, $userManager, new NullLogger());

	}//end makeHandler()

	/**
	 * Build a mocked ObjectTransitionedEvent.
	 *
	 * @param string $schema Schema slug.
	 * @param string $action Transition action.
	 * @param array<string, mixed> $objectData The object's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(string $schema, string $action, array $objectData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($objectData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getAction')->willReturn($action);

		return $event;

	}//end makeEvent()

	/**
	 * Activating a Cohort provisions its NC group, adds teacherIds/learnerIds
	 * as members, and writes ncGroupId back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-activating-a-cohort-provisions-its-nextcloud-group
	 */
	public function testActivatingCohortProvisionsGroupAndAddsMembers(): void {
		$handler = $this->makeHandler();

		$cohort = [
			'id' => 'cohort-1',
			'teacherIds' => ['teacher-1'],
			'learnerIds' => ['learner-1', 'learner-2'],
			'ncGroupId' => null,
		];

		$handler->handle($this->makeEvent(schema: 'cohort', action: 'activate', objectData: $cohort));

		self::assertSame(['learniq-cohort-cohort-1'], $this->createdGroups);
		self::assertEqualsCanonicalizing(
			['teacher-1', 'learner-1', 'learner-2'],
			$this->added['learniq-cohort-cohort-1'] ?? []
		);

		self::assertCount(1, $this->savedObjects);
		self::assertSame('cohort', $this->savedObjects[0]['schema']);
		self::assertSame('learniq-cohort-cohort-1', $this->savedObjects[0]['object']['ncGroupId']);

	}//end testActivatingCohortProvisionsGroupAndAddsMembers()

	/**
	 * A Cohort whose ncGroupId is already set is not provisioned again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-activating-an-already-provisioned-cohort-does-not-provision-a-second-group
	 */
	public function testAlreadyProvisionedCohortIsNotReProvisioned(): void {
		$handler = $this->makeHandler();

		$cohort = [
			'id' => 'cohort-2',
			'teacherIds' => ['teacher-1'],
			'learnerIds' => ['learner-1'],
			'ncGroupId' => 'learniq-cohort-cohort-2',
		];

		$handler->handle($this->makeEvent(schema: 'cohort', action: 'activate', objectData: $cohort));

		self::assertSame([], $this->createdGroups);
		self::assertSame([], $this->savedObjects);

	}//end testAlreadyProvisionedCohortIsNotReProvisioned()

	/**
	 * An unresolvable teacher/learner id is skipped, every other resolvable
	 * id is still added.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-an-unresolvable-user-id-is-skipped-not-fatal
	 */
	public function testUnresolvableUserIdIsSkippedNotFatal(): void {
		$handler = $this->makeHandler(unresolvableUsers: ['ghost-user' => true]);

		$cohort = [
			'id' => 'cohort-3',
			'teacherIds' => ['teacher-1', 'ghost-user'],
			'learnerIds' => ['learner-1'],
			'ncGroupId' => null,
		];

		$handler->handle($this->makeEvent(schema: 'cohort', action: 'activate', objectData: $cohort));

		self::assertEqualsCanonicalizing(
			['teacher-1', 'learner-1'],
			$this->added['learniq-cohort-cohort-3'] ?? []
		);
		self::assertCount(1, $this->savedObjects);

	}//end testUnresolvableUserIdIsSkippedNotFatal()

	/**
	 * Activating an Enrolment adds the learner to its Cohort's already-
	 * provisioned NC group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-enrolling-a-learner-into-an-active-cohort-adds-them-to-its-group
	 */
	public function testActivatingEnrolmentAddsLearnerToProvisionedGroup(): void {
		$group = $this->makeGroup(gid: 'learniq-cohort-cohort-4');
		$handler = $this->makeHandler(
			foundCohort: ['id' => 'cohort-4', 'ncGroupId' => 'learniq-cohort-cohort-4'],
			existingGroups: ['learniq-cohort-cohort-4' => $group]
		);

		$enrolment = ['id' => 'enrol-1', 'cohortId' => 'cohort-4', 'learnerId' => 'learner-5'];

		$handler->handle($this->makeEvent(schema: 'enrolment', action: 'activate', objectData: $enrolment));

		self::assertSame(['learner-5'], $this->added['learniq-cohort-cohort-4'] ?? []);

	}//end testActivatingEnrolmentAddsLearnerToProvisionedGroup()

	/**
	 * Withdrawing an Enrolment removes the learner from its Cohort's group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-withdrawing-an-enrolment-removes-the-learner-from-the-group
	 */
	public function testWithdrawingEnrolmentRemovesLearnerFromGroup(): void {
		$group = $this->makeGroup(gid: 'learniq-cohort-cohort-5');
		$handler = $this->makeHandler(
			foundCohort: ['id' => 'cohort-5', 'ncGroupId' => 'learniq-cohort-cohort-5'],
			existingGroups: ['learniq-cohort-cohort-5' => $group]
		);

		$enrolment = ['id' => 'enrol-2', 'cohortId' => 'cohort-5', 'learnerId' => 'learner-6'];

		$handler->handle($this->makeEvent(schema: 'enrolment', action: 'withdraw', objectData: $enrolment));

		self::assertSame(['learner-6'], $this->removed['learniq-cohort-cohort-5'] ?? []);

	}//end testWithdrawingEnrolmentRemovesLearnerFromGroup()

	/**
	 * An Enrolment change on a Cohort that has not been provisioned yet is a
	 * no-op — no group call is made, no exception is raised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#scenario-an-enrolment-change-on-a-not-yet-provisioned-cohort-is-a-no-op-not-an-error
	 */
	public function testEnrolmentChangeOnUnprovisionedCohortIsNoOp(): void {
		$handler = $this->makeHandler(foundCohort: ['id' => 'cohort-6', 'ncGroupId' => null]);

		$enrolment = ['id' => 'enrol-3', 'cohortId' => 'cohort-6', 'learnerId' => 'learner-7'];

		$handler->handle($this->makeEvent(schema: 'enrolment', action: 'activate', objectData: $enrolment));

		self::assertSame([], $this->added);
		self::assertSame([], $this->removed);

	}//end testEnrolmentChangeOnUnprovisionedCohortIsNoOp()

	/**
	 * An Enrolment with no cohortId (individually enrolled learner) is a no-op.
	 *
	 * @return void
	 */
	public function testEnrolmentWithNoCohortIdIsNoOp(): void {
		$handler = $this->makeHandler();

		$enrolment = ['id' => 'enrol-4', 'cohortId' => null, 'learnerId' => 'learner-8'];

		$handler->handle($this->makeEvent(schema: 'enrolment', action: 'activate', objectData: $enrolment));

		self::assertSame([], $this->added);

	}//end testEnrolmentWithNoCohortIdIsNoOp()

	/**
	 * A Cohort action other than `activate` is ignored.
	 *
	 * @return void
	 */
	public function testCohortNonActivateActionIgnored(): void {
		$handler = $this->makeHandler();

		$handler->handle($this->makeEvent(schema: 'cohort', action: 'archive', objectData: ['id' => 'cohort-9']));

		self::assertSame([], $this->createdGroups);
		self::assertSame([], $this->savedObjects);

	}//end testCohortNonActivateActionIgnored()

	/**
	 * A non-ObjectTransitionedEvent is ignored.
	 *
	 * @return void
	 */
	public function testNonMatchingEventTypeIgnored(): void {
		$handler = $this->makeHandler();

		$handler->handle($this->createMock(Event::class));

		self::assertSame([], $this->createdGroups);
		self::assertSame([], $this->savedObjects);

	}//end testNonMatchingEventTypeIgnored()
}//end class
