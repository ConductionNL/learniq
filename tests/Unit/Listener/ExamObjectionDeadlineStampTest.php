<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\ExamObjectionDeadlineStamp;
use OCA\Learniq\Listener\Registrar\GradingListenerRegistrar;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Listener\ExamObjectionDeadlineStamp
 * @uses   \OCA\Learniq\Service\Exam\WorkingDays
 * @uses   \OCA\Learniq\Listener\Registrar\GradingListenerRegistrar
 */
class ExamObjectionDeadlineStampTest extends TestCase {

	/**
	 * An objection filed on Friday 25 September is decided by Friday 9 October.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsTenWorkingDaysAfterSubmission(): void {
		$event = $this->create(['submittedAt' => '2026-09-25T10:00:00+02:00', 'decideBy' => '2027-01-01', 'termWorkingDays' => 99]);

		$this->stamp(term: 10)->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame('2026-10-09', $stamped['decideBy']);
		self::assertSame(10, $stamped['termWorkingDays']);
		self::assertSame('2026-09-25T10:00:00+02:00', $stamped['submittedAt']);
	}//end testTheDeadlineIsTenWorkingDaysAfterSubmission()

	/**
	 * The regulation's term comes from the setting, and an objection without a date is filed now.
	 *
	 * @return void
	 */
	public function testTheTermComesFromTheSettingAndTheDateDefaultsToNow(): void {
		$event = $this->create([]);

		$this->stamp(term: 5)->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame(5, $stamped['termWorkingDays']);
		self::assertSame(date('Y-m-d'), substr($stamped['submittedAt'], 0, 10));
		self::assertGreaterThan(substr($stamped['submittedAt'], 0, 10), $stamped['decideBy']);
	}//end testTheTermComesFromTheSettingAndTheDateDefaultsToNow()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotStamped(): void {
		$event    = new ObjectCreatingEvent(OrEntityFactory::make(['name' => 'x'], 'exemption-case'));
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('exemption-case');

		(new ExamObjectionDeadlineStamp($resolver, $this->createMock(IAppConfig::class)))->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsNotStamped()

	/**
	 * The stamp is wired for creates.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheStamp(): void {
		$wired   = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new GradingListenerRegistrar())->register($context);

		self::assertContains([ObjectCreatingEvent::class, ExamObjectionDeadlineStamp::class], $wired);
	}//end testTheRegistrarWiresTheStamp()

	/**
	 * An objection create event.
	 *
	 * @param array<string, mixed> $data The objection body.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(array $data): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make(array_merge(['gradeEntryId' => 'ge-1'], $data), 'exam-objection'));
	}//end create()

	/**
	 * The stamp with a given term setting.
	 *
	 * @param int $term The term in working days.
	 *
	 * @return ExamObjectionDeadlineStamp
	 */
	private function stamp(int $term): ExamObjectionDeadlineStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('exam-objection');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('learniq', 'exam_objection_term_working_days', 10)->willReturn($term);

		return new ExamObjectionDeadlineStamp($resolver, $config);
	}//end stamp()
}//end class
