<?php

/**
 * Edge paths of ReportPeriodLocks and PublishedGradeFreezeListener.
 *
 * The behaviour through the real write path is in
 * tests/Unit/Listener/PublishedGradeFreezeWiringTest.php and
 * tests/Unit/Lifecycle/ReportPeriodLockStoredPeriodTest.php; this file pins
 * the edges those do not reach.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Grading
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Grading;

use OCA\Learniq\Listener\PublishedGradeFreezeListener;
use OCA\Learniq\Service\Grading\ReportPeriodLocks;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Edges of the lock answer and the freeze.
 */
class ReportPeriodLocksTest extends TestCase {

	/**
	 * The lock answer for each shape a stored period can have.
	 *
	 * @return void
	 */
	public function testIsLocked(): void {
		$now = strtotime('2026-10-02T12:00:00+00:00');
		self::assertTrue(ReportPeriodLocks::isLocked(['isLocked' => true], $now));
		self::assertTrue(ReportPeriodLocks::isLocked(['lockDate' => '2026-10-01T08:00:00+00:00'], $now));
		self::assertTrue(ReportPeriodLocks::isLocked(['isLocked' => false, 'lockDate' => '2026-10-01T08:00:00+00:00'], $now));
		self::assertFalse(ReportPeriodLocks::isLocked(['lockDate' => '2026-10-03T08:00:00+00:00'], $now));
		self::assertFalse(ReportPeriodLocks::isLocked(['lockDate' => null], $now));
		self::assertFalse(ReportPeriodLocks::isLocked(['lockDate' => '  '], $now));
		self::assertFalse(ReportPeriodLocks::isLocked(['lockDate' => 'not a date'], $now));
		self::assertFalse(ReportPeriodLocks::isLocked([], $now));
	}//end testIsLocked()

	/**
	 * Which period governs an entry: none without period or plan, none when the
	 * plan is not listed, and entity rows are read like arrays.
	 *
	 * @return void
	 */
	public function testGoverning(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(
			[
				['id' => 'p0', 'curriculumPlanIds' => 'not-a-list'],
				OrEntityFactory::make(['id' => 'p1', 'curriculumPlanIds' => ['plan-1'], 'lockDate' => '2020-01-01T00:00:00+00:00'], 'report-period'),
			]
		);
		$locks = new ReportPeriodLocks(objects: $objects);

		self::assertNull($locks->governing(['period' => '', 'curriculumPlanId' => 'plan-1']));
		self::assertNull($locks->governing(['period' => 'LP1']));
		self::assertNull($locks->governing(['period' => 'LP1', 'curriculumPlanId' => 'plan-2']));
		self::assertSame('p1', $locks->governing(['period' => 'LP1', 'curriculumPlanId' => 'plan-1'])['id'] ?? null);
		self::assertSame('p1', $locks->lockedPeriodFor(['period' => 'LP1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 't'])['id'] ?? null);
		self::assertNull($locks->lockedPeriodFor(['period' => 'LP1', 'curriculumPlanId' => 'plan-2']));
	}//end testGoverning()

	/**
	 * The freeze ignores other events, creates, and objects it cannot place.
	 *
	 * @return void
	 */
	public function testTheFreezeStaysOutOfOtherWrites(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('lp-teacher');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('findAll');

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('no mapper'));
		$listener = new PublishedGradeFreezeListener($resolver, new ReportPeriodLocks(objects: $objects), $session, new NullLogger());

		$entry = OrEntityFactory::make(['id' => 'e1', 'lifecycle' => 'published', 'value' => 5.5], 'grade-entry');
		$listener->handle(new ObjectCreatingEvent($entry));

		$noOld = new ObjectUpdatingEvent($entry, null);
		$listener->handle($noOld);
		self::assertFalse($noOld->isPropagationStopped());

		$unresolved = new ObjectUpdatingEvent(OrEntityFactory::make(['id' => 'e1', 'lifecycle' => 'published', 'value' => 6.5], 'grade-entry'), $entry);
		$listener->handle($unresolved);
		self::assertFalse($unresolved->isPropagationStopped());

		$other = $this->createMock(ListenerSchemaResolver::class);
		$other->method('guardSchemaSlug')->willReturn('lvs-result');
		$listener = new PublishedGradeFreezeListener($other, new ReportPeriodLocks(objects: $objects), $session, new NullLogger());
		$listener->handle($unresolved);
		self::assertFalse($unresolved->isPropagationStopped());

		$ours = $this->createMock(ListenerSchemaResolver::class);
		$ours->method('guardSchemaSlug')->willReturn('grade-entry');
		$listener = new PublishedGradeFreezeListener($ours, new ReportPeriodLocks(objects: $objects), $session, new NullLogger());
		$sameValue = new ObjectUpdatingEvent(OrEntityFactory::make(['id' => 'e1', 'lifecycle' => 'published', 'value' => 5.5, 'visibleFrom' => '2026-10-02'], 'grade-entry'), $entry);
		$listener->handle($sameValue);
		self::assertFalse($sameValue->isPropagationStopped());
	}//end testTheFreezeStaysOutOfOtherWrites()
}//end class
