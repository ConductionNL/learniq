<?php

/**
 * A school's own absence limit next to the statutory one.
 *
 * Runs the scenarios of the requirement "A school sets its own absence limit
 * next to the statutory one": each active AttendanceThreshold is checked on
 * its own, so a learner at 9 unexcused lesson hours crosses the school's own
 * limit of 8 and gets a flag from it, while the statutory threshold of 16 is
 * not crossed and nothing is reported to the authority. A changed limit is
 * what the check reads from then on. The change's history (who changed the
 * limit) is OpenRegister's audit trail of the object, not learniq's.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-school-sets-its-own-absence-limit-next-to-the-statutory-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Learniq\Lifecycle\AttendanceThresholdCrossingGuard;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The school's own threshold and the statutory one, checked each on its own.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-school-sets-its-own-absence-limit-next-to-the-statutory-one
 */
class OwnAbsenceLimitTest extends TestCase {

	/**
	 * The learner of the scenario, at 9 unexcused lesson hours.
	 */
	private const LEARNER = 'j.bakker';

	/**
	 * The flags the handler saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $flags = [];

	/**
	 * The statutory threshold: 16 unexcused lesson hours in a rolling four weeks, reported to leerplicht.
	 *
	 * @return array<string, mixed>
	 */
	private static function statutory(): array {
		return [
			'id' => 'threshold-statutory',
			'name' => 'Leerplicht 16 uur',
			'kind' => 'leerplicht-16uur',
			'metric' => 'unexcused-lesson-hours',
			'window' => 'rolling-4-weeks',
			'scope' => 'school',
			'limit' => 16,
			'active' => true,
			'onCross' => ['dataExchangeTarget' => 'leerplicht'],
			'tenant_id' => 'school-a',
		];
	}//end statutory()

	/**
	 * The school's own early warning: 8 unexcused lesson hours in the same window, no report.
	 *
	 * @param int $limit The limit the coordinator set.
	 *
	 * @return array<string, mixed>
	 */
	private static function ownLimit(int $limit=8): array {
		return [
			'id' => 'threshold-own',
			'name' => 'Vroeg signaal bij verzuim',
			'kind' => 'generic',
			'metric' => 'unexcused-lesson-hours',
			'window' => 'rolling-4-weeks',
			'scope' => 'school',
			'limit' => $limit,
			'active' => true,
			'onCross' => [],
			'tenant_id' => 'school-a',
		];
	}//end ownLimit()

	/**
	 * A threshold as the check-threshold transition hands it: the learner and her hours stamped on.
	 *
	 * @param array<string, mixed> $threshold The threshold.
	 * @param float                $hours     Her unexcused lesson hours in the window.
	 *
	 * @return array<string, mixed>
	 */
	private static function checked(array $threshold, float $hours): array {
		return array_merge(
			$threshold,
			[
				'checkedLearnerId' => self::LEARNER,
				'checkedMetricValue' => $hours,
				'checkedWindowStart' => '2026-10-26',
				'checkedWindowEnd' => '2026-11-20',
			]
		);
	}//end checked()

	/**
	 * The guard and the flag handler run for every active threshold, each on its own.
	 *
	 * @param array<int, array<string, mixed>> $thresholds The active thresholds.
	 * @param float                            $hours      Her unexcused lesson hours.
	 * @param int                              $reports    How many authority reports the run may request.
	 *
	 * @return void
	 */
	private function checkEach(array $thresholds, float $hours, int $reports): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options=new ObjectSaveOptions()): ObjectEntity {
				$data = $object;
				if ($object instanceof ObjectEntity) {
					$data = $object->jsonSerialize();
				}

				$this->flags[] = $data;
				return OrEntityFactory::make(data: $data, schema: (string)$options->target->schema, register: 'learniq', uuid: 'flag-'.count(value: $this->flags));
			}
		);

		$integriq = $this->createMock(originalClassName: IntegriqExchangeClient::class);
		$integriq->expects($this->exactly(count: $reports))->method('requestJob')->willReturn('job-1');

		$guard   = new AttendanceThresholdCrossingGuard(logger: new NullLogger());
		$handler = new AttendanceFlagCreationHandler(objectService: $objects, integriq: $integriq, logger: new NullLogger(), schemas: TransitionScope::resolver());

		foreach ($thresholds as $threshold) {
			$object = self::checked(threshold: $threshold, hours: $hours);
			// The guarded transition runs only where the guard allows it.
			if ($guard->check(object: $object, action: 'check-threshold', userId: 'coordinator')->isAllowed() === false) {
				continue;
			}

			$handler->handle(event: $this->transitioned(threshold: $object));
		}
	}//end checkEach()

	/**
	 * The check-threshold transition of one threshold.
	 *
	 * @param array<string, mixed> $threshold The checked threshold.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitioned(array $threshold): ObjectTransitionedEvent {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($threshold);

		$event = $this->createMock(originalClassName: ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('attendance-threshold');
		$event->method('getAction')->willReturn('check-threshold');
		$event->method('getTo')->willReturn('active');

		return $event;
	}//end transitioned()

	/**
	 * Scenario "A school warns at eight hours": 9 hours crosses the school's 8, not the statutory 16.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#scenario-a-school-warns-at-eight-hours
	 */
	public function testASchoolWarnsAtEightHours(): void {
		$this->checkEach(thresholds: [self::statutory(), self::ownLimit()], hours: 9.0, reports: 0);

		$this->assertCount(expectedCount: 1, haystack: $this->flags, message: 'one flag, from the school\'s own threshold only');
		$this->assertSame(expected: 'threshold-own', actual: $this->flags[0]['attendanceThresholdId']);
		$this->assertSame(expected: self::LEARNER, actual: $this->flags[0]['learnerId']);
		$this->assertSame(expected: 9.0, actual: $this->flags[0]['metricValue']);
		$this->assertNull(actual: $this->flags[0]['dataExchangeJobId'], message: 'no report goes to the authority');
		$this->assertArrayNotHasKey(key: 'flagKind', array: $this->flags[0], message: 'not a statutory absence signal');
	}//end testASchoolWarnsAtEightHours()

	/**
	 * Past the statutory limit both thresholds flag, and only the statutory one reports.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-school-sets-its-own-absence-limit-next-to-the-statutory-one
	 */
	public function testPastTheStatutoryLimitOnlyTheStatutoryThresholdReports(): void {
		$this->checkEach(thresholds: [self::statutory(), self::ownLimit()], hours: 17.0, reports: 1);

		$this->assertSame(
			expected: ['threshold-statutory', 'threshold-own'],
			actual: array_values(array: array_unique(array: array_column(array: $this->flags, column_key: 'attendanceThresholdId')))
		);
		$this->assertSame(expected: 'signal-verzuim', actual: $this->flags[0]['flagKind']);
	}//end testPastTheStatutoryLimitOnlyTheStatutoryThresholdReports()

	/**
	 * Scenario "The school lowers its limit": at 10 hours 9 passes, changed to 8 it flags.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#scenario-the-school-lowers-its-limit
	 */
	public function testTheSchoolLowersItsLimit(): void {
		$this->checkEach(thresholds: [self::ownLimit(limit: 10)], hours: 9.0, reports: 0);
		$this->assertSame(expected: [], actual: $this->flags, message: 'below the old limit of 10 nothing is flagged');

		$this->checkEach(thresholds: [self::ownLimit(limit: 8)], hours: 9.0, reports: 0);
		$this->assertCount(expectedCount: 1, haystack: $this->flags, message: 'the check reads the changed limit of 8');
	}//end testTheSchoolLowersItsLimit()
}//end class
