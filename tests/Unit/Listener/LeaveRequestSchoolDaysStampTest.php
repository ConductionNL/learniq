<?php

/**
 * LeaveRequestSchoolDaysStamp unit tests
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-requests-decided-by-the-school/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\LeaveRequestSchoolDaysStamp;
use OCA\Learniq\Listener\Registrar\EventListenerWiring;
use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\ContactHoursReader;
use OCA\Learniq\Service\Leave\LeaveCalendar;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The server counts the school days and sets the deadline, whatever the form sent.
 */
class LeaveRequestSchoolDaysStampTest extends TestCase {

	/**
	 * Wednesday 30 September 2026, 10:00 UTC.
	 */
	private const NOW = 1790762400;

	/**
	 * The stamp over a school year with no holidays, as the given schema.
	 *
	 * @param string $slug What the schema resolver answers.
	 *
	 * @return LeaveRequestSchoolDaysStamp
	 */
	private function stamp(string $slug = 'leave-request'): LeaveRequestSchoolDaysStamp {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);
		$reader = $this->createMock(ContactHoursReader::class);
		$reader->method('reportPeriods')->willReturn([]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new LeaveRequestSchoolDaysStamp(
			schemaResolver: $schemaResolver,
			calendar: new LeaveCalendar(reader: $reader, schoolYear: new SchoolYear(), appConfig: $config),
			timeFactory: $time
		);
	}//end stamp()

	/**
	 * Hamza's wedding leave, Thursday 29 and Friday 30 October, asked on Wednesday 30 September.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(): array {
		return ['learnerRef' => 'pupil-hamza', 'dateFrom' => '2026-10-29', 'dateTo' => '2026-10-30', 'kind' => 'wedding', 'reason' => 'Bruiloft in de familie'];
	}//end request()

	/**
	 * A new request gets two school days and the answer date five school days on, even when the form sent other values.
	 *
	 * @return void
	 */
	public function testANewRequestGetsItsSchoolDaysAndDeadline(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::request(), ['schoolDays' => 1, 'decideBy' => '2027-01-01']), 'leave-request'));

		$this->stamp()->handle($event);

		self::assertSame(['schoolDays' => 2, 'decideBy' => '2026-10-07'], $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testANewRequestGetsItsSchoolDaysAndDeadline()

	/**
	 * Twelve school days are counted as twelve, so only a forward is possible.
	 *
	 * @return void
	 */
	public function testALongRangeIsCountedInFull(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::request(), ['dateFrom' => '2026-11-02', 'dateTo' => '2026-11-17']), 'leave-request'));

		$this->stamp()->handle($event);

		self::assertSame(12, $event->getModifiedData()['schoolDays']);
	}//end testALongRangeIsCountedInFull()

	/**
	 * An update recounts the school days from the new range and keeps the stored deadline.
	 *
	 * @return void
	 */
	public function testAnUpdateRecountsAndKeepsTheDeadline(): void {
		$stored = array_merge(self::request(), ['schoolDays' => 2, 'decideBy' => '2026-10-07', 'lifecycle' => 'submitted']);
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['dateTo' => '2026-11-03', 'schoolDays' => 3, 'decideBy' => '2026-12-31']), 'leave-request'),
			OrEntityFactory::make($stored, 'leave-request')
		);

		$this->stamp()->handle($event);

		self::assertSame(['schoolDays' => 4, 'decideBy' => '2026-10-07'], $event->getModifiedData());
	}//end testAnUpdateRecountsAndKeepsTheDeadline()

	/**
	 * A range whose last day lies before its first is refused and nothing is stamped.
	 *
	 * @return void
	 */
	public function testABackwardsRangeIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::request(), ['dateTo' => '2026-10-28']), 'leave-request'));

		$this->stamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['reason' => 'leave-range-invalid', 'message' => LeaveRequestSchoolDaysStamp::INVALID_RANGE], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
	}//end testABackwardsRangeIsRefused()

	/**
	 * Another schema, or a write another listener already stopped, is left alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreLeftAlone(): void {
		$other = new ObjectCreatingEvent(OrEntityFactory::make(self::request(), 'excuse-request'));
		$this->stamp(slug: 'excuse-request')->handle($other);
		self::assertSame([], $other->getModifiedData());

		$stopped = new ObjectCreatingEvent(OrEntityFactory::make(self::request(), 'leave-request'));
		$stopped->stopPropagation();
		$this->stamp()->handle($stopped);
		self::assertSame([], $stopped->getModifiedData());
	}//end testOtherWritesAreLeftAlone()

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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LeaveRequestSchoolDaysStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LeaveRequestSchoolDaysStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
