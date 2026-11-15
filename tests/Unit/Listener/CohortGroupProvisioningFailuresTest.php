<?php

/**
 * Unit tests for CohortGroupProvisioningHandler's refusals and failures: what
 * it does when a cohort, group or user cannot be resolved.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\CohortGroupProvisioningHandler;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Listener\CohortGroupProvisioningHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class CohortGroupProvisioningFailuresTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	/** @var IUserManager&MockObject */
	private IUserManager $userManager;

	/**
	 * Logged lines: [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	private CohortGroupProvisioningHandler $handler;

	/**
	 * Build the handler over mocked boundaries and a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logged = [];
		$this->objectService = $this->createMock(ObjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		$this->handler = new CohortGroupProvisioningHandler(
			$this->objectService,
			$this->groupManager,
			$this->userManager,
			$logger,
			TransitionScope::resolver()
		);
	}//end setUp()

	/**
	 * A transition event.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $action The action.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $schema, string $action, array $data): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($data);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getAction')->willReturn($action);
		$event->method('getObject')->willReturn($object);
		return $event;
	}//end event()

	/**
	 * The single logged line, as [level, message-substring-check, context].
	 *
	 * @param string               $level    The expected level.
	 * @param string               $fragment A fragment of the message.
	 * @param array<string, mixed> $context  The expected context.
	 *
	 * @return void
	 */
	private function assertLoggedOnce(string $level, string $fragment, array $context): void {
		self::assertCount(1, $this->logged, 'exactly one line is logged');
		self::assertSame($level, $this->logged[0][0]);
		self::assertStringContainsString($fragment, $this->logged[0][1]);
		self::assertSame($context, $this->logged[0][2]);
	}//end assertLoggedOnce()

	/**
	 * A cohort without an id is not provisioned.
	 *
	 * @return void
	 */
	public function testACohortWithoutAnIdIsNotProvisioned(): void {
		$this->groupManager->expects(self::never())->method('createGroup');
		$this->objectService->expects(self::never())->method('saveObject');

		$this->handler->handle($this->event('cohort', 'activate', ['name' => 'Klas 1A']));

		$this->assertLoggedOnce('warning', 'Cohort has no id', []);
	}//end testACohortWithoutAnIdIsNotProvisioned()

	/**
	 * A group manager that throws leaves the cohort unprovisioned, with a warning.
	 *
	 * @return void
	 */
	public function testAThrowingGroupManagerLeavesTheCohortUnprovisioned(): void {
		$this->groupManager->method('groupExists')->willThrowException(new RuntimeException('backend down'));
		$this->objectService->expects(self::never())->method('saveObject');

		$this->handler->handle($this->event('cohort', 'activate', ['uuid' => 'c-1']));

		$this->assertLoggedOnce('warning', 'Failed to create/resolve', ['gid' => 'learniq-cohort-c-1', 'id' => 'c-1', 'msg' => 'backend down']);
	}//end testAThrowingGroupManagerLeavesTheCohortUnprovisioned()

	/**
	 * A group that cannot be created leaves the cohort unprovisioned, with a warning.
	 *
	 * @return void
	 */
	public function testAGroupThatCannotBeCreatedLeavesTheCohortUnprovisioned(): void {
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects(self::once())->method('createGroup')->with('learniq-cohort-c-1')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$this->handler->handle($this->event('cohort', 'activate', ['id' => 'c-1']));

		$this->assertLoggedOnce('warning', 'could not be created', ['gid' => 'learniq-cohort-c-1', 'id' => 'c-1']);
	}//end testAGroupThatCannotBeCreatedLeavesTheCohortUnprovisioned()

	/**
	 * Members that do not resolve, or whose lookup throws, are skipped; the cohort is still provisioned.
	 *
	 * @return void
	 */
	public function testUnresolvableMembersAreSkipped(): void {
		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->with('learniq-cohort-c-1')->willReturn($group);
		$teacher = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturnCallback(
			static function (string $uid) use ($teacher): ?IUser {
				if ($uid === 'boom') {
					throw new RuntimeException('lookup failed');
				}

				return $uid === 'teacher' ? $teacher : null;
			}
		);
		$group->expects(self::once())->method('addUser')->with($teacher);
		$this->objectService->expects(self::once())->method('saveObject')->with(
			['id' => 'c-1', 'teacherIds' => ['teacher', '', 7], 'learnerIds' => ['gone', 'boom', 'teacher'], 'ncGroupId' => 'learniq-cohort-c-1'],
			self::anything(),
			'learniq',
			'cohort'
		);

		$this->handler->handle(
			$this->event('cohort', 'activate', ['id' => 'c-1', 'teacherIds' => ['teacher', '', 7], 'learnerIds' => ['gone', 'boom', 'teacher']])
		);

		self::assertSame('debug', $this->logged[0][0]);
		self::assertStringContainsString('Failed to resolve IUser', $this->logged[0][1]);
		self::assertSame(['userId' => 'boom', 'msg' => 'lookup failed'], $this->logged[0][2]);
		self::assertSame(['gid' => 'learniq-cohort-c-1', 'id' => 'c-1', 'n' => 3], $this->logged[1][2], 'members counted before resolving');
	}//end testUnresolvableMembersAreSkipped()

	/**
	 * An enrolment without a cohort or learner, or with another action, touches nothing.
	 *
	 * @return void
	 */
	public function testEnrolmentsWithoutCohortOrLearnerAreIgnored(): void {
		$this->objectService->expects(self::never())->method('findAll');

		$this->handler->handle($this->event('enrolment', 'activate', ['learnerId' => 'l-1']));
		$this->handler->handle($this->event('enrolment', 'withdraw', ['cohortId' => 'c-1']));
		$this->handler->handle($this->event('enrolment', 'complete', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		self::assertSame([], $this->logged);
	}//end testEnrolmentsWithoutCohortOrLearnerAreIgnored()

	/**
	 * A cohort that is not found skips the sync.
	 *
	 * @return void
	 */
	public function testAMissingCohortSkipsTheSync(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			['ids' => ['c-1'], 'filters' => ['register' => 'learniq', 'schema' => 'cohort'], 'limit' => 1]
		)->willReturn([]);
		$this->groupManager->expects(self::never())->method('get');

		$this->handler->handle($this->event('enrolment', 'activate', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		$this->assertLoggedOnce('debug', 'not found', ['id' => 'c-1', 'learner' => 'l-1']);
	}//end testAMissingCohortSkipsTheSync()

	/**
	 * A cohort entity without a group id skips the sync.
	 *
	 * @return void
	 */
	public function testAnUnprovisionedCohortSkipsTheSync(): void {
		$cohort = $this->createMock(ObjectEntity::class);
		$cohort->method('jsonSerialize')->willReturn(['id' => 'c-1', 'ncGroupId' => '']);
		$this->objectService->method('findAll')->willReturn([$cohort]);
		$this->groupManager->expects(self::never())->method('get');

		$this->handler->handle($this->event('enrolment', 'withdraw', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		$this->assertLoggedOnce('debug', 'has no ncGroupId', ['id' => 'c-1', 'learner' => 'l-1']);
	}//end testAnUnprovisionedCohortSkipsTheSync()

	/**
	 * A group lookup that throws skips the sync with a warning.
	 *
	 * @return void
	 */
	public function testAThrowingGroupLookupSkipsTheSync(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 'c-1', 'ncGroupId' => 'g-1']]);
		$this->groupManager->method('get')->willThrowException(new RuntimeException('no backend'));
		$this->userManager->expects(self::never())->method('get');

		$this->handler->handle($this->event('enrolment', 'activate', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		$this->assertLoggedOnce('warning', 'Failed to resolve NC group', ['gid' => 'g-1', 'msg' => 'no backend']);
	}//end testAThrowingGroupLookupSkipsTheSync()

	/**
	 * A group that no longer exists skips the sync with a warning.
	 *
	 * @return void
	 */
	public function testADeletedGroupSkipsTheSync(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 'c-1', 'ncGroupId' => 'g-1']]);
		$this->groupManager->method('get')->with('g-1')->willReturn(null);
		$this->userManager->expects(self::never())->method('get');

		$this->handler->handle($this->event('enrolment', 'activate', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		$this->assertLoggedOnce('warning', 'no longer exists', ['gid' => 'g-1', 'id' => 'c-1']);
	}//end testADeletedGroupSkipsTheSync()

	/**
	 * A learner who is not a Nextcloud user is neither added nor removed.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAUserIsSkipped(): void {
		$group = $this->createMock(IGroup::class);
		$group->expects(self::never())->method('addUser');
		$group->expects(self::never())->method('removeUser');
		$this->objectService->method('findAll')->willReturn([['id' => 'c-1', 'ncGroupId' => 'g-1']]);
		$this->groupManager->method('get')->willReturn($group);
		$this->userManager->method('get')->with('l-1')->willReturn(null);

		$this->handler->handle($this->event('enrolment', 'withdraw', ['cohortId' => 'c-1', 'learnerId' => 'l-1']));

		$this->assertLoggedOnce('debug', 'does not resolve to an NC user', ['learner' => 'l-1']);
	}//end testALearnerWithoutAUserIsSkipped()
}//end class
