<?php

/**
 * Learniq CheckInWindowStamp unit tests.
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\CheckInWindowStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CheckInWindowStamp::handle().
 */
class CheckInWindowStampTest extends TestCase {

	private const SESSION = 'a0000000-0000-4000-8000-000000000001';
	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * 2026-10-21T09:00:00+00:00.
	 */
	private const NOW = 1792573200;

	/**
	 * Build the stamp, signed in as $userId, over a lesson ending at $endsAt.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $endsAt When the lesson ends.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return CheckInWindowStamp
	 */
	private function makeStamp(?string $userId, string $endsAt = '2026-10-21T10:00:00+00:00', string $slug = 'check-in-window'): CheckInWindowStamp {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(['id' => self::SESSION, 'endsAt' => $endsAt, 'tenant_id' => self::TENANT], 'session')
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new CheckInWindowStamp(
			schemaResolver: $schemaResolver,
			objectService: $objectService,
			userSession: $session,
			timeFactory: $time,
		);
	}//end makeStamp()

	/**
	 * A window opened with only the lesson and the mode gets its owner,
	 * tenant and default times from the server.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
	 */
	public function testAWindowGetsItsOwnerTenantAndTimesFromTheServer(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['sessionId' => self::SESSION, 'mode' => 'rotating-qr'], 'check-in-window'));
		$this->makeStamp(userId: 'teacher-1')->handle($event);

		self::assertSame(
			[
				'openedBy' => 'teacher-1',
				'tenant_id' => self::TENANT,
				'opensAt' => '2026-10-21T09:00:00+00:00',
				'closesAt' => '2026-10-21T09:15:00+00:00',
				'lateAfterMinutes' => 5,
			],
			$event->getModifiedData()
		);
	}//end testAWindowGetsItsOwnerTenantAndTimesFromTheServer()

	/**
	 * A client cannot open a window in someone else's name or tenant, nor
	 * past the end of the lesson.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
	 */
	public function testAClientCannotNameAnotherOwnerTenantOrCloseTime(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(
				[
					'sessionId' => self::SESSION,
					'mode' => 'link',
					'openedBy' => 'teacher-2',
					'tenant_id' => 'other-tenant',
					'opensAt' => '2026-10-21T09:50:00+00:00',
					'closesAt' => '2026-10-21T12:00:00+00:00',
					'lateAfterMinutes' => 10,
				],
				'check-in-window'
			)
		);
		$this->makeStamp(userId: 'teacher-1')->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('teacher-1', $data['openedBy']);
		self::assertSame(self::TENANT, $data['tenant_id']);
		self::assertSame('2026-10-21T09:50:00+00:00', $data['opensAt']);
		self::assertSame('2026-10-21T10:00:00+00:00', $data['closesAt']);
		self::assertSame(10, $data['lateAfterMinutes']);
	}//end testAClientCannotNameAnotherOwnerTenantOrCloseTime()

	/**
	 * An update keeps the stored owner and tenant.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredOwnerAndTenant(): void {
		$stored = ['sessionId' => self::SESSION, 'mode' => 'link', 'openedBy' => 'teacher-1', 'tenant_id' => self::TENANT, 'lifecycle' => 'open'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'closed', 'openedBy' => 'teacher-2', 'tenant_id' => 'x']), 'check-in-window'),
			OrEntityFactory::make($stored, 'check-in-window')
		);
		$this->makeStamp(userId: 'teacher-2')->handle($event);

		self::assertSame(['openedBy' => 'teacher-1', 'tenant_id' => self::TENANT], $event->getModifiedData());
	}//end testAnUpdateKeepsTheStoredOwnerAndTenant()

	/**
	 * Another schema's writes are left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['sessionId' => self::SESSION, 'openedBy' => 'x'], 'session'));
		$this->makeStamp(userId: 'teacher-1', slug: 'session')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired on both create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . CheckInWindowStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . CheckInWindowStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
