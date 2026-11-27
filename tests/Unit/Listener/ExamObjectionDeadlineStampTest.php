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
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
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
		$event = $this->create(data: ['submittedAt' => '2026-09-25T10:00:00+02:00', 'decideBy' => '2027-01-01', 'termWorkingDays' => 99]);

		$this->stamp(term: 10)->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame(expected: '2026-10-09', actual: $stamped['decideBy']);
		self::assertSame(expected: 10, actual: $stamped['termWorkingDays']);
		self::assertSame(expected: '2026-09-25T10:00:00+02:00', actual: $stamped['submittedAt']);
	}//end testTheDeadlineIsTenWorkingDaysAfterSubmission()

	/**
	 * The regulation's term comes from the setting, and an objection without a date is filed now.
	 *
	 * @return void
	 */
	public function testTheTermComesFromTheSettingAndTheDateDefaultsToNow(): void {
		$event = $this->create(data: []);

		$this->stamp(term: 5)->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame(expected: 5, actual: $stamped['termWorkingDays']);
		self::assertSame(expected: date('Y-m-d'), actual: substr($stamped['submittedAt'], 0, 10));
		self::assertGreaterThan(expected: substr($stamped['submittedAt'], 0, 10), actual: $stamped['decideBy']);
	}//end testTheTermComesFromTheSettingAndTheDateDefaultsToNow()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotStamped(): void {
		$event    = new ObjectCreatingEvent(OrEntityFactory::make(['name' => 'x'], 'exemption-case'));
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('exemption-case');

		(
			new ExamObjectionDeadlineStamp(schemaResolver: $resolver, appConfig: $this->createMock(originalClassName: IAppConfig::class))
		)->handle($event);

		self::assertSame(expected: [], actual: $event->getModifiedData());
	}//end testAnotherSchemaIsNotStamped()

	/**
	 * The stamp is wired for creates.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheStamp(): void {
		$wired   = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new GradingListenerRegistrar())->register($context);

		self::assertContains(needle: [ObjectCreatingEvent::class, ExamObjectionDeadlineStamp::class], haystack: $wired);
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
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('exam-objection');
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->with('learniq', 'exam_objection_term_working_days', 10)->willReturn($term);

		return new ExamObjectionDeadlineStamp(schemaResolver: $resolver, appConfig: $config);
	}//end stamp()
}//end class
