<?php

/**
 * Learniq ExcuseApprovalHandler tests
 *
 * Pins what approving an excuse request does: which attendance records are
 * read, which are flipped to absent-excused, and what is logged when the
 * request is incomplete or its dates cannot be read.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExcuseApprovalHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Approving an excuse request.
 */
class ExcuseApprovalHandlerTest extends TestCase {

	/**
	 * Every read.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * Every save.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every log line, as `level: message` with its context filled in.
	 *
	 * @var list<string>
	 */
	private array $logs = [];

	/**
	 * Approve an excuse request over a store holding these records.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<int, mixed>    $records The absent-unexcused records the store answers.
	 *
	 * @return void
	 */
	private function approve(array $request, array $records): void {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use ($records): array {
				$this->reads[] = $config;
				return $records;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$this->saves[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				return OrEntityFactory::make((array)$object, (string)$schema);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context) use ($level): void {
					$filled = $message;
					foreach ($context as $key => $value) {
						$filled = str_replace('{'.$key.'}', (string)$value, $filled);
					}

					$this->logs[] = $level.': '.$filled;
				}
			);
		}

		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturn('learniq');
		$schemas->method('eventSchema')->willReturn('excuse-request');
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($request);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($object);
		$event->method('getTo')->willReturn('approved');

		(new ExcuseApprovalHandler($store, $logger, $schemas))->handle($event);
	}//end approve()

	/**
	 * The learner's absent-unexcused records marked inside the window are
	 * flipped and stamped with the request; the count is logged.
	 *
	 * @return void
	 */
	public function testRecordsInsideTheWindowAreFlipped(): void {
		$this->approve(
			['id' => 'er-1', 'learnerId' => 'pupil-1', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-06'],
			[
				['id' => 'ar-1', 'markedAt' => '2026-10-05T08:30:00+00:00', 'status' => 'absent-unexcused'],
				OrEntityFactory::make(['markedAt' => '2026-10-06T12:00:00+00:00', 'status' => 'absent-unexcused'], 'attendance-record', 'learniq', 'ar-2'),
				['id' => 'ar-3', 'markedAt' => '2026-10-07T08:30:00+00:00'],
				['id' => 'ar-4', 'markedAt' => ''],
				['id' => 'ar-5', 'markedAt' => 'not a date'],
				['id' => 'ar-6'],
			]
		);

		self::assertSame(
			[
				[
					'filters' => ['register' => 'learniq', 'schema' => 'attendance-record', 'learnerId' => 'pupil-1', 'status' => 'absent-unexcused'],
					'limit' => 1000,
				],
			],
			$this->reads
		);
		self::assertSame(['learniq', 'learniq'], array_column($this->saves, 'register'));
		self::assertSame(['attendance-record', 'attendance-record'], array_column($this->saves, 'schema'));
		self::assertSame(['id' => 'ar-1', 'markedAt' => '2026-10-05T08:30:00+00:00', 'status' => 'absent-excused', 'excuseRequestId' => 'er-1'], $this->saves[0]['object']);
		self::assertSame(['absent-excused', 'er-1'], [$this->saves[1]['object']['status'], $this->saves[1]['object']['excuseRequestId']]);
		self::assertSame(
			['info: [ExcuseApprovalHandler] ExcuseRequest er-1 approved — flipped 2 AttendanceRecord(s) to absent-excused for learner pupil-1.'],
			$this->logs
		);
	}//end testRecordsInsideTheWindowAreFlipped()

	/**
	 * An incomplete request or unreadable dates are logged and read nothing;
	 * the request is named by its uuid when it has no id.
	 *
	 * @return void
	 */
	public function testIncompleteRequestsAndBadDatesReadNothing(): void {
		$this->approve(['uuid' => 'er-u', 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-06'], []);
		$this->approve(['id' => 'er-2', 'learnerId' => 'pupil-1', 'dateFrom' => '', 'dateTo' => '2026-10-06'], []);
		$this->approve(['id' => '', 'uuid' => 'er-3', 'learnerId' => 'pupil-1', 'dateFrom' => '2026-10-05'], []);
		$this->approve(['id' => 'er-4', 'learnerId' => 'pupil-1', 'dateFrom' => 'next monday', 'dateTo' => '2026-10-06'], []);
		$this->approve(['id' => 'er-5', 'learnerId' => 'pupil-1', 'dateFrom' => 'bogus', 'dateTo' => '2026-10-06'], []);

		self::assertSame([], $this->reads);
		self::assertSame(
			[
				'warning: [ExcuseApprovalHandler] ExcuseRequest er-u missing learnerId/dateFrom/dateTo — skipping.',
				'warning: [ExcuseApprovalHandler] ExcuseRequest er-2 missing learnerId/dateFrom/dateTo — skipping.',
				'warning: [ExcuseApprovalHandler] ExcuseRequest er-3 missing learnerId/dateFrom/dateTo — skipping.',
				'warning: [ExcuseApprovalHandler] ExcuseRequest er-4 dates are not ISO 8601 (next monday–2026-10-06) — skipping.',
				'warning: [ExcuseApprovalHandler] ExcuseRequest er-5 has unparsable dates (bogus–2026-10-06) — skipping.',
			],
			$this->logs
		);
	}//end testIncompleteRequestsAndBadDatesReadNothing()

	/**
	 * An approved illness without a last day excuses every record from its
	 * first day on, and none before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-report-may-cover-lesson-hours-and-an-illness-may-stay-open-until-the-pupil-is-better
	 */
	public function testAnOpenIllnessExcusesEveryDayFromItsFirst(): void {
		$this->approve(
			['id' => 'er-ill', 'learnerId' => 'pupil-1', 'dateFrom' => '2026-10-05', 'reasonKind' => 'illness'],
			[
				['id' => 'ar-before', 'markedAt' => '2026-10-02T08:30:00+00:00', 'status' => 'absent-unexcused'],
				['id' => 'ar-mon', 'markedAt' => '2026-10-05T08:30:00+00:00', 'status' => 'absent-unexcused'],
				['id' => 'ar-thu', 'markedAt' => '2026-10-08T08:30:00+00:00', 'status' => 'absent-unexcused'],
			]
		);

		self::assertSame(['ar-mon', 'ar-thu'], array_map(static fn (array $save): string => $save['object']['id'], $this->saves));
		self::assertSame(['er-ill', 'er-ill'], array_map(static fn (array $save): string => $save['object']['excuseRequestId'], $this->saves));
	}//end testAnOpenIllnessExcusesEveryDayFromItsFirst()

	/**
	 * Any other reason without a last day is still incomplete.
	 *
	 * @return void
	 */
	public function testAnOpenAppointmentIsStillIncomplete(): void {
		$this->approve(['id' => 'er-app', 'learnerId' => 'pupil-1', 'dateFrom' => '2026-10-05', 'reasonKind' => 'medical-appointment'], []);

		self::assertSame([], $this->reads);
		self::assertSame(['warning: [ExcuseApprovalHandler] ExcuseRequest er-app missing learnerId/dateFrom/dateTo — skipping.'], $this->logs);
	}//end testAnOpenAppointmentIsStillIncomplete()
}//end class
