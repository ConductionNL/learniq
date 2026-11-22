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
use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
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
	 * Build the listener for a caller.
	 *
	 * @param string|null $uid  The signed-in user, or null for a system write.
	 * @param string      $slug The schema the resolver reports.
	 *
	 * @return AdmissionAdviceStamp
	 */
	private function stamp(?string $uid, string $slug = 'admission-advice'): AdmissionAdviceStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid) . ' de Vries');
		}

		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): mixed {
				$data = ($this->objects[(string)$options?->schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return OrEntityFactory::make($data, (string)$options?->schema);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = array_filter(
					$this->stored,
					static fn (array $row): bool => $row['applicationId'] === ($filters['applicationId'] ?? '') && $row['memberId'] === ($filters['memberId'] ?? '')
				);

				return array_map(static fn (array $row) => OrEntityFactory::make($row, 'admission-advice'), array_values($rows));
			}
		);

		return new AdmissionAdviceStamp(
			schemaResolver: $resolver,
			userSession: $session,
			objectService: $objectService,
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
		return new ObjectCreatingEvent(OrEntityFactory::make($advice, 'admission-advice'));
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
		return new ObjectUpdatingEvent(OrEntityFactory::make($new, 'admission-advice'), OrEntityFactory::make($old, 'admission-advice'));
	}//end update()

	/**
	 * A panel member's advice is stored in her name, whatever the client sent, with the application's tenant.
	 *
	 * @return void
	 */
	public function testAPanelMemberAdvisesInHerOwnName(): void {
		$event = $this->create(['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place', 'memberId' => 'bram', 'memberName' => 'Someone']);

		$this->stamp(uid: 'anna')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$stored = $event->getObject()->getObject();
		self::assertSame('anna', $stored['memberId']);
		self::assertSame('Anna de Vries', $stored['memberName']);
		self::assertSame('tenant-a', $stored['tenant_id']);
	}//end testAPanelMemberAdvisesInHerOwnName()

	/**
	 * Someone not on the round's panel gives no advice.
	 *
	 * @return void
	 */
	public function testSomeoneOffThePanelIsRefused(): void {
		$event = $this->create(['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place']);

		$this->stamp(uid: 'dirk')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('admission-advice-not-on-panel', $event->getErrors()['reason']);
	}//end testSomeoneOffThePanelIsRefused()

	/**
	 * Once decided, an application takes no more advice, so the advice behind the decision stays.
	 *
	 * @return void
	 */
	public function testADecidedApplicationTakesNoAdvice(): void {
		$create = $this->create(['id' => 'adv-1', 'applicationId' => 'app-2', 'advice' => 'reject']);
		$this->stamp(uid: 'anna')->handle($create);
		self::assertSame('admission-advice-not-awaiting-decision', $create->getErrors()['reason']);

		$old = ['id' => 'adv-2', 'applicationId' => 'app-2', 'advice' => 'place', 'memberId' => 'anna'];
		$change = $this->update($old, array_merge($old, ['advice' => 'reject']));
		$this->stamp(uid: 'anna')->handle($change);
		self::assertTrue($change->isPropagationStopped());
		self::assertSame('admission-advice-not-awaiting-decision', $change->getErrors()['reason']);
	}//end testADecidedApplicationTakesNoAdvice()

	/**
	 * A member advises once per application; a second advice is refused.
	 *
	 * @return void
	 */
	public function testAMemberAdvisesOnce(): void {
		$this->stored = [['id' => 'adv-1', 'applicationId' => 'app-1', 'memberId' => 'anna', 'advice' => 'place']];
		$event = $this->create(['id' => 'adv-9', 'applicationId' => 'app-1', 'advice' => 'reject']);

		$this->stamp(uid: 'anna')->handle($event);

		self::assertSame('admission-advice-already-given', $event->getErrors()['reason']);
	}//end testAMemberAdvisesOnce()

	/**
	 * The member changes her own advice before the decision; another member cannot change it.
	 *
	 * @return void
	 */
	public function testOnlyTheMemberChangesHerAdvice(): void {
		$old = ['id' => 'adv-1', 'applicationId' => 'app-1', 'advice' => 'place', 'memberId' => 'anna', 'memberName' => 'Anna de Vries', 'tenant_id' => 'tenant-a'];

		$own = $this->update($old, array_merge($old, ['advice' => 'waitlist']));
		$this->stamp(uid: 'anna')->handle($own);
		self::assertFalse($own->isPropagationStopped());
		self::assertSame('waitlist', $own->getNewObject()->getObject()['advice']);

		$other = $this->update($old, array_merge($old, ['advice' => 'reject']));
		$this->stamp(uid: 'bram')->handle($other);
		self::assertSame('admission-advice-not-yours', $other->getErrors()['reason']);

		$moved = $this->update($old, array_merge($old, ['applicationId' => 'app-3']));
		$this->stamp(uid: 'anna')->handle($moved);
		self::assertSame('admission-advice-other-application', $moved->getErrors()['reason']);
	}//end testOnlyTheMemberChangesHerAdvice()

	/**
	 * Advice that names no existing application is refused.
	 *
	 * @return void
	 */
	public function testAdviceOnAnUnknownApplicationIsRefused(): void {
		$event = $this->create(['id' => 'adv-1', 'applicationId' => 'nope', 'advice' => 'place']);

		$this->stamp(uid: 'anna')->handle($event);

		self::assertSame('admission-advice-unknown-application', $event->getErrors()['reason']);
	}//end testAdviceOnAnUnknownApplicationIsRefused()

	/**
	 * A system write and another schema's write are left alone.
	 *
	 * @return void
	 */
	public function testSystemWritesAndOtherSchemasPass(): void {
		$system = $this->create(['id' => 'adv-1', 'applicationId' => 'app-2', 'advice' => 'place', 'memberId' => 'anna']);
		$this->stamp(uid: null)->handle($system);
		self::assertFalse($system->isPropagationStopped());
		self::assertSame('anna', $system->getObject()->getObject()['memberId']);

		$other = $this->create(['id' => 'x', 'applicationId' => 'nope']);
		$this->stamp(uid: 'dirk', slug: 'lesson-note')->handle($other);
		self::assertFalse($other->isPropagationStopped());
	}//end testSystemWritesAndOtherSchemasPass()

	/**
	 * The stamp is wired before every advice create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . AdmissionAdviceStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . AdmissionAdviceStamp::class, $registered);
	}//end testTheStampIsRegistered()
}//end class
