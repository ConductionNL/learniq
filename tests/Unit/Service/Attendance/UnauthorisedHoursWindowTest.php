<?php

/**
 * Learniq UnauthorisedHoursWindow unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Attendance
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
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Attendance;

use OCA\Learniq\Service\Attendance\UnauthorisedHoursWindow;
use PHPUnit\Framework\TestCase;

/**
 * The rolling count of lesuren without permission, read against a threshold.
 */
class UnauthorisedHoursWindowTest extends TestCase {

	/**
	 * A 16-lesuren threshold over four rolling weeks.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function threshold(array $extra=[]): array {
		return array_merge(
			[
				'name' => 'Leerplicht 16 uur',
				'metric' => 'unexcused-lesuren',
				'limit' => 16,
				'window' => ['type' => 'rolling-weeks', 'weeks' => 4],
				'scope' => 'per-learner',
				'active' => true,
			],
			$extra
		);
	}//end threshold()

	/**
	 * A number of unexcused records on lessons s-0 … s-(n-1).
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function records(int $count): array {
		$records = [];
		for ($i = 0; $i < $count; $i++) {
			$records[] = ['sessionId' => 's-' . $i, 'status' => 'absent-unexcused'];
		}

		return $records;
	}//end records()

	/**
	 * Lessons s-0 … s-(n-1) on one day.
	 *
	 * @param int      $count   How many.
	 * @param string   $date    Their day.
	 * @param int|null $minutes Their length.
	 *
	 * @return array<string, array{date: string, minutes: int|null}>
	 */
	private static function sessions(int $count, string $date, ?int $minutes=null): array {
		$sessions = [];
		for ($i = 0; $i < $count; $i++) {
			$sessions['s-' . $i] = ['date' => $date, 'minutes' => $minutes];
		}

		return $sessions;
	}//end sessions()

	/**
	 * Only an active per-learner unexcused-lesuren threshold over rolling weeks has a count.
	 *
	 * @return void
	 */
	public function testOnlyARollingUnexcusedLesurenThresholdApplies(): void {
		$window = new UnauthorisedHoursWindow();

		self::assertTrue($window->applies(threshold: self::threshold()));
		self::assertFalse($window->applies(threshold: self::threshold(['metric' => 'late-count'])));
		self::assertFalse($window->applies(threshold: self::threshold(['window' => ['type' => 'school-year']])));
		self::assertFalse($window->applies(threshold: self::threshold(['window' => ['type' => 'rolling-weeks']])));
		self::assertFalse($window->applies(threshold: self::threshold(['active' => false])));
		self::assertFalse($window->applies(threshold: self::threshold(['scope' => 'per-cohort'])));
		self::assertFalse($window->applies(threshold: self::threshold(['limit' => null])));
	}//end testOnlyARollingUnexcusedLesurenThresholdApplies()

	/**
	 * The window is the threshold's weeks ending on and including today.
	 *
	 * @return void
	 */
	public function testTheWindowEndsTodayAndSpansTheWeeks(): void {
		$window = new UnauthorisedHoursWindow();
		$records = [
			['sessionId' => 'today', 'status' => 'absent-unexcused'],
			['sessionId' => 'first', 'status' => 'absent-unexcused'],
			['sessionId' => 'before', 'status' => 'absent-unexcused'],
			['sessionId' => 'after', 'status' => 'absent-unexcused'],
			['sessionId' => 'excused', 'status' => 'absent-excused'],
		];
		$sessions = [
			'today' => ['date' => '2026-09-21', 'minutes' => null],
			'first' => ['date' => '2026-08-25', 'minutes' => null],
			'before' => ['date' => '2026-08-24', 'minutes' => null],
			'after' => ['date' => '2026-09-22', 'minutes' => null],
			'excused' => ['date' => '2026-09-21', 'minutes' => null],
		];

		self::assertSame(2.0, $window->count(records: $records, sessions: $sessions, threshold: self::threshold(), today: '2026-09-21'));
	}//end testTheWindowEndsTodayAndSpansTheWeeks()

	/**
	 * A lesson counts as its length in lesuren; one without a length counts as one.
	 *
	 * @return void
	 */
	public function testALessonCountsAsItsLengthInLesuren(): void {
		$window = new UnauthorisedHoursWindow();
		$records = [
			['sessionId' => 'long', 'status' => 'absent-unexcused'],
			['sessionId' => 'unknown', 'status' => 'absent-unexcused'],
			['sessionId' => 'missing', 'status' => 'absent-unexcused', 'markedAt' => '2026-09-20T10:00:00+02:00'],
			['sessionId' => 'nowhere', 'status' => 'absent-unexcused'],
		];
		$sessions = [
			'long' => ['date' => '2026-09-21', 'minutes' => 90],
			'unknown' => ['date' => '2026-09-21', 'minutes' => null],
		];

		// 90 of 60 = 1.5, plus 1 for the unknown length, plus 1 dated by markedAt; the undated one is not counted.
		self::assertSame(3.5, $window->count(records: $records, sessions: $sessions, threshold: self::threshold(), today: '2026-09-21'));
		self::assertSame(4.0, $window->count(records: $records, sessions: $sessions, threshold: self::threshold(['lessonHourMinutes' => 45]), today: '2026-09-21'));
	}//end testALessonCountsAsItsLengthInLesuren()

	/**
	 * Without warnAtHours a threshold warns at three quarters of its limit.
	 *
	 * @return void
	 */
	public function testTheWarningLevel(): void {
		$window = new UnauthorisedHoursWindow();

		self::assertSame(12.0, $window->warnAt(threshold: self::threshold()));
		self::assertSame(10.0, $window->warnAt(threshold: self::threshold(['warnAtHours' => 10])));
		self::assertSame(12.0, $window->warnAt(threshold: self::threshold(['warnAtHours' => -1])));
	}//end testTheWarningLevel()

	/**
	 * A count is nearing from the warning level up to, not including, the limit.
	 *
	 * @return void
	 */
	public function testNearingIsBetweenTheWarningAndTheLimit(): void {
		$window = new UnauthorisedHoursWindow();

		self::assertFalse($window->nearing(hours: 11.9, threshold: self::threshold()));
		self::assertTrue($window->nearing(hours: 12.0, threshold: self::threshold()));
		self::assertTrue($window->nearing(hours: 15.9, threshold: self::threshold()));
		self::assertFalse($window->nearing(hours: 16.0, threshold: self::threshold()), 'at the limit the flag takes over');
	}//end testNearingIsBetweenTheWarningAndTheLimit()

	/**
	 * Staff read the count against the limit in Dutch.
	 *
	 * @return void
	 */
	public function testTheLabel(): void {
		self::assertSame('14 van 16 uur', UnauthorisedHoursWindow::label(hours: 14.0, limit: 16.0));
		self::assertSame('13,5 van 16 uur', UnauthorisedHoursWindow::label(hours: 13.5, limit: 16.0));
	}//end testTheLabel()

	/**
	 * Finn at 14 of 16 is nearing; a lesson excused back to 10 is not any more.
	 *
	 * @return void
	 */
	public function testAPupilNearingAndDroppingBelow(): void {
		$window = new UnauthorisedHoursWindow();
		$sessions = self::sessions(14, '2026-09-15');

		$fields = $window->fields(records: self::records(14), sessions: $sessions, threshold: self::threshold(), today: '2026-09-21');
		self::assertSame(
			['unauthorisedHoursWindow' => 14.0, 'thresholdHours' => 16.0, 'windowLabel' => '14 van 16 uur', 'thresholdNearing' => true],
			$fields
		);

		$fields = $window->fields(records: self::records(10), sessions: $sessions, threshold: self::threshold(), today: '2026-09-21');
		self::assertSame(10.0, $fields['unauthorisedHoursWindow']);
		self::assertFalse($fields['thresholdNearing']);
	}//end testAPupilNearingAndDroppingBelow()

	/**
	 * Without an applicable threshold there is no count and no warning.
	 *
	 * @return void
	 */
	public function testNoThresholdNoCount(): void {
		$window = new UnauthorisedHoursWindow();
		$none = ['unauthorisedHoursWindow' => null, 'thresholdHours' => null, 'windowLabel' => null, 'thresholdNearing' => false];

		self::assertSame($none, $window->fields(records: self::records(14), sessions: self::sessions(14, '2026-09-15'), threshold: null, today: '2026-09-21'));
		self::assertSame($none, $window->fields(records: self::records(14), sessions: self::sessions(14, '2026-09-15'), threshold: self::threshold(['metric' => 'late-count']), today: '2026-09-21'));
	}//end testNoThresholdNoCount()
}//end class
