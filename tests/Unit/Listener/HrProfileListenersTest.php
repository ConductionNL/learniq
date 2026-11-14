<?php

/**
 * HrFieldOwnershipGuard and ProfileScopeChangeHandler on the real
 * OpenRegister event classes (compliance-assign-from-hr-roles).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#requirement-the-hr-system-owns-a-persons-department-roles-manager-and-job-title
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\Learniq\Listener\HrFieldOwnershipGuard;
use OCA\Learniq\Listener\ProfileScopeChangeHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Listener\HrFieldOwnershipGuard
 * @covers \OCA\Learniq\Listener\ProfileScopeChangeHandler
 */
class HrProfileListenersTest extends TestCase {

	/**
	 * A resolver that names every entity by the schema it was made with.
	 *
	 * @return ListenerSchemaResolverInterface
	 */
	private function resolver(): ListenerSchemaResolverInterface {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn (?object $entity): string => (string)$entity?->getSchema());

		return $resolver;
	}//end resolver()

	/**
	 * The guard, with the caller signed in as $uid (null: no session) and the feed account $feed.
	 *
	 * @param string|null $uid  The caller.
	 * @param string      $feed The configured HR feed account.
	 *
	 * @return HrFieldOwnershipGuard
	 */
	private function guard(?string $uid, string $feed='integriq-sync'): HrFieldOwnershipGuard {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $key === HrFieldOwnershipGuard::FEED_USER_KEY ? $feed : $default);

		return new HrFieldOwnershipGuard(schemaResolver: $this->resolver(), userSession: $session, appConfig: $config);
	}//end guard()

	/**
	 * An update of a profile.
	 *
	 * @param array<string, mixed> $old    The stored profile.
	 * @param array<string, mixed> $new    The written profile.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function updating(array $old, array $new, string $schema='learner-profile'): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(OrEntityFactory::make($new, $schema), OrEntityFactory::make($old, $schema));
	}//end updating()

	/**
	 * hr changing an AFAS-fed department is refused with a message naming AFAS.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-hr-edits-an-hr-fed-department
	 */
	public function testHrCannotChangeAnHrFedDepartment(): void {
		$event = self::updating(
			old: ['ncUserId' => 'anna', 'department' => 'Spooronderhoud', 'roles' => ['learner'], 'hrSource' => 'afas'],
			new: ['ncUserId' => 'anna', 'department' => 'Facilitair', 'roles' => ['learner'], 'hrSource' => 'afas']
		);

		$this->guard(uid: 'hr-ellen')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['reason' => 'hr-owned-field', 'message' => 'department comes from afas and can only be changed there.'], $event->getErrors());
	}//end testHrCannotChangeAnHrFedDepartment()

	/**
	 * The feed account, a background write, an unchanged or reordered value,
	 * a field left out, a hand-made profile and another schema all pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-a-hand-made-profile-stays-editable
	 */
	public function testWhatPasses(): void {
		$fed = ['department' => 'Spooronderhoud', 'roles' => ['learner', 'manager'], 'hrSource' => 'afas'];
		$moved = array_merge($fed, ['department' => 'Facilitair']);
		$cases = [
			'feed account'      => [$this->guard(uid: 'integriq-sync'), self::updating(old: $fed, new: $moved)],
			'background write'  => [$this->guard(uid: null), self::updating(old: $fed, new: $moved)],
			'roles reordered'   => [$this->guard(uid: 'hr-ellen'), self::updating(old: $fed, new: array_merge($fed, ['roles' => ['manager', 'learner'], 'givenName' => 'Anna']))],
			'field left out'    => [$this->guard(uid: 'hr-ellen'), self::updating(old: $fed, new: ['hrSource' => 'afas', 'givenName' => 'Anna'])],
			'hand-made profile' => [$this->guard(uid: 'hr-ellen'), self::updating(old: ['department' => 'Spooronderhoud'], new: ['department' => 'Facilitair'])],
			'other schema'      => [$this->guard(uid: 'hr-ellen'), self::updating(old: $fed, new: $moved, schema: 'enrolment')],
			'no feed account'   => [$this->guard(uid: '', feed: ''), self::updating(old: $fed, new: array_merge($fed, ['givenName' => 'Anna']))],
		];

		foreach ($cases as $label => [$guard, $event]) {
			$guard->handle($event);
			self::assertFalse($event->isPropagationStopped(), $label);
			self::assertSame([], $event->getErrors(), $label);
		}
	}//end testWhatPasses()

	/**
	 * Every changed HR field is named, manager and job title included.
	 *
	 * @return void
	 */
	public function testEveryChangedHrFieldIsNamed(): void {
		$event = self::updating(
			old: ['managerId' => 'boss', 'jobTitle' => 'Monteur', 'hrSource' => 'humaniq'],
			new: ['managerId' => 'other', 'jobTitle' => 'Ploegleider', 'hrSource' => 'humaniq']
		);

		$this->guard(uid: 'hr-ellen', feed: '')->handle($event);

		self::assertSame('managerId, jobTitle comes from humaniq and can only be changed there.', $event->getErrors()['message']);
	}//end testEveryChangedHrFieldIsNamed()

	/**
	 * The handler with a recording assignment service.
	 *
	 * @param list<array<string, mixed>> $calls Receives each assignPerson() call.
	 *
	 * @return ProfileScopeChangeHandler
	 */
	private function handler(array &$calls): ProfileScopeChangeHandler {
		$assignment = $this->createMock(RegulationAssignmentServiceInterface::class);
		$assignment->method('assignPerson')->willReturnCallback(
			function (array $profile, string $today) use (&$calls): array {
				$calls[] = ['profile' => $profile, 'today' => $today];
				return ['created' => [], 'outOfScope' => []];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794564000);

		return new ProfileScopeChangeHandler(assignment: $assignment, schemaResolver: $this->resolver(), time: $time, logger: new NullLogger());
	}//end handler()

	/**
	 * A created profile and a change of department, roles or job title re-run
	 * the assignment for that person, with today's date; a change of anything
	 * else, a reordered list and another schema do not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-assign-from-hr-roles/specs/compliance-rule-coverage/spec.md#scenario-someone-moves-to-a-department-in-scope
	 */
	public function testAScopeChangeReRunsTheAssignmentForThatPerson(): void {
		$calls = [];
		$handler = $this->handler($calls);
		$profile = ['ncUserId' => 'anna', 'department' => 'Facilitair', 'roles' => ['learner', 'hr'], 'jobTitle' => 'Monteur'];

		$handler->handle(new ObjectCreatedEvent(OrEntityFactory::make($profile, 'learner-profile', 'learniq', 'p-anna')));
		$handler->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($profile, ['department' => 'Spooronderhoud']), 'learner-profile'), OrEntityFactory::make($profile, 'learner-profile')));
		$handler->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($profile, ['jobTitle' => 'Ploegleider']), 'learner-profile'), OrEntityFactory::make($profile, 'learner-profile')));
		$handler->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($profile, ['givenName' => 'Anna']), 'learner-profile'), OrEntityFactory::make($profile, 'learner-profile')));
		$handler->handle(new ObjectUpdatedEvent(OrEntityFactory::make(array_merge($profile, ['roles' => ['hr', 'learner']]), 'learner-profile'), OrEntityFactory::make($profile, 'learner-profile')));
		$handler->handle(new ObjectCreatedEvent(OrEntityFactory::make($profile, 'enrolment')));

		self::assertCount(3, $calls);
		self::assertSame('p-anna', $calls[0]['profile']['id']);
		self::assertSame('Spooronderhoud', $calls[1]['profile']['department']);
		self::assertSame('Ploegleider', $calls[2]['profile']['jobTitle']);
		self::assertSame(['2026-11-13', '2026-11-13', '2026-11-13'], array_column($calls, 'today'));
	}//end testAScopeChangeReRunsTheAssignmentForThatPerson()
}//end class
