<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Tests\Support\SaveCallShape;
use OCA\Learniq\Lifecycle\SessionChangeGuard;
use OCA\Learniq\Listener\SessionChangeNoticeHandler;
use OCA\Learniq\Service\SessionChangeBatchService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\SessionChangeInput;
use OCA\Learniq\Timetabling\SessionSeries;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins apply() against golden/SessionChangeBatch.json: per lesson the
 * guard question (with the user it was asked for), the writes, the
 * transitions, the restore after a failed write, and the stored batch. The
 * guard allows only the acting caller, so a check for anyone else, or for a
 * user id the request carries, changes the golden. The random batch id is
 * written as {batch}.
 *
 * @covers \OCA\Learniq\Service\SessionChangeBatchService
 * @uses   \OCA\Learniq\Timetabling\SessionSeries
 * @uses   \OCA\Learniq\Timetabling\SessionChangeInput
 */
class SessionChangeBatchGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/SessionChangeBatch.json';

	/**
	 * Calls on the collaborators, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every batch is applied, refused and stored as recorded.
	 *
	 * @return void
	 */
	public function testApplyMatchesGolden(): void {
		$all = ['s-open', 's-done', 's-gone', 's-denied', 's-fails', 's-blank', 's-restore-fails', 's-progress', 's-noid'];
		$batches = [
			'cancel by the planner' => [['kind' => 'cancel', 'sessionIds' => $all, 'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Ziek'], 'planner'],
			'substitute by the planner' => [['kind' => 'substitute', 'sessionIds' => $all, 'changeReasonKind' => 'teacher-absence', 'substituteTeacherId' => 'sub'], 'planner'],
			'room by the planner' => [['kind' => 'room', 'sessionIds' => ['s-open', 's-progress', 's-fails'], 'changeReasonKind' => 'room-unavailable', 'roomId' => 'r-9'], 'planner'],
			'a forged maker in the request' => [['kind' => 'cancel', 'sessionIds' => ['s-open'], 'changeReasonKind' => 'other', 'madeBy' => 'planner', 'userId' => 'planner', 'callerId' => 'planner'], 'intruder'],
			'nothing applied' => [['kind' => 'cancel', 'sessionIds' => ['s-done', 's-gone'], 'changeReasonKind' => 'other'], 'planner'],
		];

		$actual = [];
		foreach ($batches as $name => [$input, $callerId]) {
			$this->calls = [];
			$logger = new CapturingLogger();
			$batch = $this->service(logger: $logger)->apply(input: $input, callerId: $callerId);
			$id = (string)($batch['id'] ?? '');
			$record = ['batch' => $batch, 'calls' => $this->calls, 'log' => $logger->records];
			$actual[$name] = json_decode(str_replace($id, '{batch}', (string)json_encode($record)), true);
		}

		$this->assertMatchesGolden(file: self::GOLDEN, actual: $actual);
	}//end testApplyMatchesGolden()

	/**
	 * The service over fixed lessons.
	 *
	 * @param CapturingLogger $logger The logger.
	 *
	 * @return SessionChangeBatchService
	 */
	private function service(CapturingLogger $logger): SessionChangeBatchService {
		$base = ['cohortId' => 'c-1', 'courseId' => 'k-1', 'tenant_id' => 't-1', 'lifecycle' => 'scheduled', 'roomId' => 'r-1'];
		$lessons = [
			's-open' => $base + ['id' => 's-open', 'startsAt' => '2026-03-03T10:15:00+01:00'],
			's-done' => ['id' => 's-done', 'startsAt' => '2026-03-04T10:15:00+01:00', 'lifecycle' => 'completed'] + $base,
			's-denied' => $base + ['id' => 's-denied', 'startsAt' => '2026-03-05T10:15:00+01:00'],
			's-fails' => ['id' => 's-fails', 'startsAt' => '2026-03-29T01:30:00+00:00', 'tenant_id' => ''] + $base,
			's-blank' => $base + ['id' => 's-blank', 'startsAt' => '2026-03-06T10:15:00+01:00'],
			's-restore-fails' => $base + ['id' => 's-restore-fails', 'startsAt' => '2026-03-07T10:15:00+01:00'],
			's-progress' => ['id' => 's-progress', 'startsAt' => '2026-03-10T23:30:00+00:00', 'lifecycle' => 'in-progress', 'tenant_id' => 't-2'] + $base,
			's-noid' => ['startsAt' => 'not a date'] + $base,
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($lessons) {
				$this->calls[] = ['find' => $id];
				if ($id === 's-gone') {
					throw new DoesNotExistException('gone');
				}

				return OrEntityFactory::make($lessons[$id], 'session');
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				$named = SaveCallShape::all(args: $args);
				$object = $named['object'];
				$this->calls[] = ['save' => [$named['schema'] ?? null, $named['uuid'] ?? null, $object]];
				if (($object['id'] ?? '') === 's-restore-fails' && ($object['changeBatchId'] ?? null) === null) {
					throw new RuntimeException('restore refused');
				}

				if (in_array($object['id'] ?? '', ['s-fails', 's-restore-fails'], true) === true && ($object['changeBatchId'] ?? null) !== null && $object['changeReasonKind'] !== 'room-unavailable') {
					throw new class('raw message') extends RuntimeException {
						/**
						 * Validation errors, as OpenRegister's exceptions carry them.
						 *
						 * @return array<string,string>
						 */
						public function getErrors(): array {
							return ['message' => 'Dit lokaal is bezet.'];
						}
					};
				}

				return OrEntityFactory::make($object, (string)($named['schema'] ?? ''), 'learniq', $named['uuid'] ?? null);
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action) use ($lessons) {
				$this->calls[] = ['transition' => [$objectId, $action]];
				if ($objectId === 's-blank') {
					throw new RuntimeException('   ');
				}

				if ($objectId === 's-fails') {
					throw new RuntimeException('transition refused');
				}

				return OrEntityFactory::make($lessons[$objectId] ?? [], 'session');
			}
		);

		$guard = $this->createMock(SessionChangeGuard::class);
		$guard->method('check')->willReturnCallback(
			function (array $object, string $action, string $userId) {
				$this->calls[] = ['guard' => [(string)($object['id'] ?? ''), $action, $userId, $object['substituteTeacherId'] ?? null, $object['roomId'] ?? null]];
				if ($userId !== 'planner') {
					return GuardResult::deny('Only the planner may change lessons.');
				}

				if (($object['id'] ?? '') === 's-denied') {
					return GuardResult::deny('Not your department.');
				}

				return GuardResult::allow();
			}
		);

		$notices = $this->createMock(SessionChangeNoticeHandler::class);
		$notices->method('affectedPeople')->willReturnCallback(
			static fn (array $session): array => ['learnerIds' => ['l-1', 'l-' . ($session['id'] ?? '')], 'parentIds' => ['p-1']]
		);

		return new SessionChangeBatchService($objects, new SessionSeries($objects), new SessionChangeInput(), $engine, $guard, $notices, $logger);
	}//end service()
}//end class
