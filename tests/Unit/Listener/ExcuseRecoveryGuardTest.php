<?php

/**
 * Unit tests for ExcuseRecoveryGuard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-report-may-cover-lesson-hours-and-an-illness-may-stay-open-until-the-pupil-is-better
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ExcuseRecoveryGuard;
use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Ending an open illness: "Better on Wednesday".
 *
 * @covers \OCA\Learniq\Listener\ExcuseRecoveryGuard
 * @uses   \OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar
 */
class ExcuseRecoveryGuardTest extends TestCase {

	/**
	 * Sami's open illness from Monday 5 October.
	 *
	 * @var array<string, mixed>
	 */
	private const OPEN_ILLNESS = [
		'learnerRef' => 'lp-sami',
		'dateFrom' => '2026-10-05',
		'reasonKind' => 'illness',
		'reason' => 'Koorts',
		'lifecycle' => 'submitted',
	];

	/**
	 * The guard, for a portal write (no session) or a staff write.
	 *
	 * @param bool   $staff  Whether a Nextcloud user is signed in.
	 * @param string $schema The schema the written object is of.
	 *
	 * @return ExcuseRecoveryGuard
	 */
	private function guard(bool $staff=false, string $schema='excuse-request'): ExcuseRecoveryGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schema);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($staff === true ? $this->createMock(IUser::class) : null);

		return new ExcuseRecoveryGuard(
			schemaResolver: $resolver,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $session,
		);
	}//end guard()

	/**
	 * An update from the stored report to the new one.
	 *
	 * @param array<string, mixed> $stored The stored report.
	 * @param array<string, mixed> $change What the write changes.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function update(array $stored, array $change): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, $change), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);
	}//end update()

	/**
	 * The parent reports recovery with last day Tuesday: the report ends on Tuesday.
	 *
	 * @return void
	 */
	public function testBetterOnWednesdayEndsTheReportOnTuesday(): void {
		$event = self::update(self::OPEN_ILLNESS, ['dateTo' => '2026-10-06']);

		$this->guard()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testBetterOnWednesdayEndsTheReportOnTuesday()

	/**
	 * A last day before the first day is refused, for the portal and for staff.
	 *
	 * @return void
	 */
	public function testALastDayBeforeTheFirstDayIsRefused(): void {
		foreach ([false, true] as $staff) {
			$event = self::update(self::OPEN_ILLNESS, ['dateTo' => '2026-10-02']);

			$this->guard(staff: $staff)->handle($event);

			self::assertTrue($event->isPropagationStopped());
			self::assertSame(
				['reason' => 'excuse-recovery-before-start', 'message' => 'The last day cannot be before the first day of the absence.'],
				$event->getErrors()
			);
		}
	}//end testALastDayBeforeTheFirstDayIsRefused()

	/**
	 * The portal cannot move the last day of a report that already ends; staff can.
	 *
	 * @return void
	 */
	public function testOnlyAnOpenReportIsEndedFromThePortal(): void {
		$ended = array_merge(self::OPEN_ILLNESS, ['dateTo' => '2026-10-06']);

		$portal = self::update($ended, ['dateTo' => '2026-10-09']);
		$this->guard()->handle($portal);
		self::assertTrue($portal->isPropagationStopped());
		self::assertSame('excuse-recovery-not-open', $portal->getErrors()['reason']);
		self::assertSame('This absence report already has a last day. Ask the school to change it.', $portal->getErrors()['message']);

		$staff = self::update($ended, ['dateTo' => '2026-10-09']);
		$this->guard(staff: true)->handle($staff);
		self::assertFalse($staff->isPropagationStopped());
	}//end testOnlyAnOpenReportIsEndedFromThePortal()

	/**
	 * A write that leaves the last day alone, empties it, or is about another
	 * schema or a create, is not this guard's.
	 *
	 * @return void
	 */
	public function testOtherWritesPass(): void {
		$ended = array_merge(self::OPEN_ILLNESS, ['dateTo' => '2026-10-06']);
		$writes = [
			[$this->guard(), self::update($ended, ['lifecycle' => 'approved'])],
			[$this->guard(), self::update($ended, ['dateTo' => '2026-10-06T00:00:00Z'])],
			[$this->guard(), self::update(self::OPEN_ILLNESS, ['reason' => 'Griep'])],
			[$this->guard(schema: 'attendance-record'), self::update($ended, ['dateTo' => '2026-10-01'])],
		];
		foreach ($writes as $index => [$guard, $event]) {
			$guard->handle($event);
			self::assertFalse($event->isPropagationStopped(), (string)$index);
		}

		$create = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::OPEN_ILLNESS, ['dateTo' => '2026-10-01']), 'excuse-request'));
		$this->guard()->handle($create);
		self::assertFalse($create->isPropagationStopped());
	}//end testOtherWritesPass()

	/**
	 * The guard listens to updates.
	 *
	 * @return void
	 */
	public function testItIsRegisteredOnUpdates(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains([ObjectUpdatingEvent::class, ExcuseRecoveryGuard::class], $registered);
	}//end testItIsRegisteredOnUpdates()
}//end class
