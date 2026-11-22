<?php

/**
 * Learniq AdmissionAdviceStamp unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\AdmissionAdviceStamp;
use OCA\Learniq\Listener\Registrar\SchedulingListenerRegistrar;
use OCA\Learniq\Service\AdmissionPanelReads;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Only the round's panel members advise, once each, before the decision; the server names the member.
 */
class AdmissionAdviceStampTest extends TestCase {

	/**
	 * Objects the listener can read, by schema and id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $objects = [
		'admission' => [
			'app-1' => ['id' => 'app-1', 'admissionsRoundId' => 'round-1', 'lifecycle' => 'intake-completed', 'tenant_id' => 'tenant-a'],
			'app-2' => ['id' => 'app-2', 'admissionsRoundId' => 'round-1', 'lifecycle' => 'placed', 'tenant_id' => 'tenant-a'],
		],
		'admissions-round' => [
			'round-1' => ['id' => 'round-1', 'panelMemberIds' => ['anna', 'bram', 'cem']],
		],
	];

	/**
	 * Advice already stored, as findAll answers it.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $stored = [];

	/**
	 * Whether every read fails, as a database outage would.
	 *
	 * @var boolean
	 */
	private bool $readsFail = false;

	/**
	 * The reads over a mocked ObjectService answering $objects and $stored.
	 *
	 * @return AdmissionPanelReads
	 */
	private function reads(): AdmissionPanelReads {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			callback: function (int|string $id, ?ObjectFindOptions $options = null): mixed {
				if ($this->readsFail === true) {
					throw new RuntimeException(message: 'database gone');
				}

				$data = ($this->objects[(string)$options?->schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return OrEntityFactory::make(data: $data, schema: (string)$options?->schema);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			callback: function (array $config): array {
				$applicationId = ($config['filters']['applicationId'] ?? '');
				$rows = array_filter($this->stored, static fn (array $row): bool => $row['applicationId'] === $applicationId);

				return array_map(static fn (array $row) => OrEntityFactory::make(data: $row, schema: 'admission-advice'), array_values($rows));
			}
		);

		return new AdmissionPanelReads(objectService: $objectService, logger: new NullLogger());
	}//end reads()

	/**
	 * Build the listener for a caller.
	 *
	 * @param string|null $uid  The signed-in user, or null for a system write.
	 * @param string      $slug The schema the resolver reports.
	 *
	 * @return AdmissionAdviceStamp
	 */
	private function stamp(?string $uid, string $slug = 'admission-advice'): AdmissionAdviceStamp {
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn(value: $slug);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn(value: $uid);
			$displayName = ucfirst($uid) . ' de Vries';
			if ($uid === 'cem') {
				$displayName = '';
			}

			$user->method('getDisplayName')->willReturn(value: $displayName);
		}

		$session->method('getUser')->willReturn(value: $user);

		return new AdmissionAdviceStamp(
			schemaResolver: $resolver,
			userSession: $session,
			reads: $this->reads(),
			logger: new NullLogger(),
		);
	}//end stamp()

	/**
	 * A create event for an advice.
	 *
	 * @param array<string,mixed> $advice The advice.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(array $advice): ObjectCreatingEvent {
		return new ObjectCreatingEvent(object: OrEntityFactory::make(data: $advice, schema: 'admission-advice'));
	}//end create()

	/**
	 * An update event from the stored advice to the new one.
	 *
	 * @param array<string,mixed> $old The stored advice.
	 * @param array<string,mixed> $new The advice as written.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			newObject: OrEntityFactory::make(data: $new, schema: 'admission-advice'),
			oldObject: OrEntityFactory::make(data: $old, schema: 'admission-advice')
		);
	}//end update()

	/**
	 * A panel member's advice is stored in her name, whatever the client sent, with the application's tenant.
	 *
	 * @return void
	 */
	public function testAPanelMemberAdvisesInHerOwnName(): void {
		$event = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place', 'memberId' => 'bram', 'memberName' => 'Someone']);

		$this->stamp(uid: 'anna')->handle(event: $event);

		self::assertFalse(condition: $event->isPropagationStopped());
		$stored = $event->getObject()->getObject();
		self::assertSame(expected: 'anna', actual: $stored['memberId']);
		self::assertSame(expected: 'Anna de Vries', actual: $stored['memberName']);
		self::assertSame(expected: 'tenant-a', actual: $stored['tenant_id']);
	}//end testAPanelMemberAdvisesInHerOwnName()

	/**
	 * A member without a display name is named by her user id.
	 *
	 * @return void
	 */
	public function testAMemberWithoutADisplayNameIsNamedByHerId(): void {
		$event = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place', 'tenant_id' => 'tenant-b']);

		$this->stamp(uid: 'cem')->handle(event: $event);

		self::assertSame(expected: 'cem', actual: $event->getObject()->getObject()['memberName']);
		self::assertSame(expected: 'tenant-b', actual: $event->getObject()->getObject()['tenant_id']);
	}//end testAMemberWithoutADisplayNameIsNamedByHerId()

	/**
	 * Someone not on the round's panel gives no advice.
	 *
	 * @return void
	 */
	public function testSomeoneOffThePanelIsRefused(): void {
		$event = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place']);

		$this->stamp(uid: 'dirk')->handle(event: $event);

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertSame(expected: 'admission-advice-not-on-panel', actual: $event->getErrors()['reason']);
	}//end testSomeoneOffThePanelIsRefused()

	/**
	 * Once decided, an application takes no more advice, so the advice behind the decision stays.
	 *
	 * @return void
	 */
	public function testADecidedApplicationTakesNoAdvice(): void {
		$create = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-2', 'advice' => 'reject']);
		$this->stamp(uid: 'anna')->handle(event: $create);
		self::assertSame(expected: 'admission-advice-not-awaiting-decision', actual: $create->getErrors()['reason']);

		$old = ['id' => 'adv-2', 'applicationId' => 'app-2', 'advice' => 'place', 'memberId' => 'anna'];
		$change = $this->update(old: $old, new: array_merge($old, ['advice' => 'reject']));
		$this->stamp(uid: 'anna')->handle(event: $change);
		self::assertTrue(condition: $change->isPropagationStopped());
		self::assertSame(expected: 'admission-advice-not-awaiting-decision', actual: $change->getErrors()['reason']);
	}//end testADecidedApplicationTakesNoAdvice()

	/**
	 * A member advises once per application; a second advice is refused.
	 *
	 * @return void
	 */
	public function testAMemberAdvisesOnce(): void {
		$this->stored = [
			['id' => 'adv-0', 'applicationId' => 'app-1', 'memberId' => 'bram', 'advice' => 'place'],
			['id' => 'adv-1', 'applicationId' => 'app-1', 'memberId' => 'anna', 'advice' => 'place'],
		];
		$event = $this->create(advice: ['id' => 'adv-9', 'applicationId' => 'app-1', 'advice' => 'reject']);

		$this->stamp(uid: 'anna')->handle(event: $event);

		self::assertSame(expected: 'admission-advice-already-given', actual: $event->getErrors()['reason']);
	}//end testAMemberAdvisesOnce()

	/**
	 * The member changes her own advice before the decision; another member cannot change it.
	 *
	 * @return void
	 */
	public function testOnlyTheMemberChangesHerAdvice(): void {
		$old = [
			'id' => 'adv-1',
			'applicationId' => 'app-1',
			'advice' => 'place',
			'memberId' => 'anna',
			'memberName' => 'Anna de Vries',
			'tenant_id' => 'tenant-a',
		];
		$this->stored = [$old];

		$own = $this->update(old: $old, new: array_merge($old, ['advice' => 'waitlist']));
		$this->stamp(uid: 'anna')->handle(event: $own);
		self::assertFalse(condition: $own->isPropagationStopped());
		self::assertSame(expected: 'waitlist', actual: $own->getNewObject()->getObject()['advice']);

		$other = $this->update(old: $old, new: array_merge($old, ['advice' => 'reject']));
		$this->stamp(uid: 'bram')->handle(event: $other);
		self::assertSame(expected: 'admission-advice-not-yours', actual: $other->getErrors()['reason']);

		$moved = $this->update(old: $old, new: array_merge($old, ['applicationId' => 'app-3']));
		$this->stamp(uid: 'anna')->handle(event: $moved);
		self::assertSame(expected: 'admission-advice-other-application', actual: $moved->getErrors()['reason']);
	}//end testOnlyTheMemberChangesHerAdvice()

	/**
	 * Advice that names no existing application, or none, or one that cannot be read, is refused.
	 *
	 * @return void
	 */
	public function testAdviceOnAnUnknownApplicationIsRefused(): void {
		foreach (['nope', ''] as $applicationId) {
			$event = $this->create(advice: ['id' => 'adv-1', 'applicationId' => $applicationId, 'advice' => 'place']);
			$this->stamp(uid: 'anna')->handle(event: $event);
			self::assertSame(expected: 'admission-advice-unknown-application', actual: $event->getErrors()['reason']);
		}

		$this->readsFail = true;
		$event = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place']);
		$this->stamp(uid: 'anna')->handle(event: $event);
		self::assertSame(expected: 'admission-advice-unknown-application', actual: $event->getErrors()['reason']);
	}//end testAdviceOnAnUnknownApplicationIsRefused()

	/**
	 * A system write and another schema's write are left alone.
	 *
	 * @return void
	 */
	public function testSystemWritesAndOtherSchemasPass(): void {
		$system = $this->create(advice: ['id' => 'adv-1', 'applicationId' => 'app-2', 'advice' => 'place', 'memberId' => 'anna']);
		$this->stamp(uid: null)->handle(event: $system);
		self::assertFalse(condition: $system->isPropagationStopped());
		self::assertSame(expected: 'anna', actual: $system->getObject()->getObject()['memberId']);

		$other = $this->create(advice: ['id' => 'x', 'applicationId' => 'nope']);
		$this->stamp(uid: 'dirk', slug: 'lesson-note')->handle(event: $other);
		self::assertFalse(condition: $other->isPropagationStopped());
	}//end testSystemWritesAndOtherSchemasPass()

	/**
	 * A schema the resolver cannot name is not ours: the write passes.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaPasses(): void {
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willThrowException(exception: new RuntimeException(message: 'unknown schema'));
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'dirk');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(value: $user);
		$event = $this->create(advice: ['id' => 'x', 'applicationId' => 'nope']);

		$stamp = new AdmissionAdviceStamp(schemaResolver: $resolver, userSession: $session, reads: $this->reads(), logger: new NullLogger());
		$stamp->handle(event: $event);

		self::assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnknownSchemaPasses()

	/**
	 * The stamp is wired before every advice create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			callback: static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new SchedulingListenerRegistrar())->register(context: $context);

		self::assertContains(needle: ObjectCreatingEvent::class . ' => ' . AdmissionAdviceStamp::class, haystack: $registered);
		self::assertContains(needle: ObjectUpdatingEvent::class . ' => ' . AdmissionAdviceStamp::class, haystack: $registered);
	}//end testTheStampIsRegistered()
}//end class
