<?php

/**
 * Learniq self check-in unit tests: the code and the check-in rules.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CheckIn
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CheckIn;

use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The real code service and check-in service over a register-faithful store
 * and a fixed clock.
 */
class CheckInServiceTest extends TestCase {

	/**
	 * 2026-09-29 08:32:00 UTC, two minutes into the lesson.
	 */
	private const NOW = 1790670720;

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The clock's current time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * Whether the last write ran inside runAs(), and for whom.
	 *
	 * @var string|null
	 */
	private ?string $ranAs = null;

	/**
	 * The code service over a fixed secret and the test clock.
	 *
	 * @return CheckInCodeService
	 */
	private function codes(): CheckInCodeService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('test-secret');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CheckInCodeService(config: $config, random: $this->createMock(ISecureRandom::class), time: $time);
	}//end codes()

	/**
	 * The check-in service over a store with one lesson, its group and an
	 * open window.
	 *
	 * @param array<string, mixed> $window Window overrides.
	 *
	 * @return CheckInService
	 */
	private function service(array $window = []): CheckInService {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['check-in-window'] = [array_merge(
			['id' => 'win-1', 'sessionId' => 'ses-1', 'mode' => 'rotating-qr', 'lateAfterMinutes' => 5, 'opensAt' => '2026-09-29T08:25:00+00:00', 'closesAt' => '2026-09-29T08:45:00+00:00', 'lifecycle' => 'open', 'tenant_id' => 't1'],
			$window
		)];
		$this->store->rows['session'] = [['id' => 'ses-1', 'cohortId' => 'coh-1', 'title' => 'Wiskunde B', 'startsAt' => '2026-09-29T08:30:00+00:00', 'endsAt' => '2026-09-29T09:20:00+00:00']];
		$this->store->rows['cohort'] = [['id' => 'coh-1', 'learnerIds' => ['pupil-1', 'pupil-2']]];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new DoesNotExistException('gone');
			}
		);
		$objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation): mixed {
				$this->ranAs = $user->getUID();
				return $operation();
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CheckInService(objects: $objects, codes: $this->codes(), time: $time);
	}//end service()

	/**
	 * The record rows written.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		return ($this->store->rows['attendance-record'] ?? []);
	}//end records()

	/**
	 * The current and the previous code pass; a code from two steps ago and a
	 * code for another window do not.
	 *
	 * @return void
	 */
	public function testTheRotatingCodeLivesTwoSteps(): void {
		$codes = $this->codes();
		$code = $codes->current(windowId: 'win-1', mode: 'rotating-qr');
		self::assertMatchesRegularExpression('/^[A-Z2-7]{8}$/', $code);
		self::assertFalse($codes->verify(windowId: 'win-2', mode: 'rotating-qr', code: $code));

		$this->now += 30;
		self::assertTrue($codes->verify(windowId: 'win-1', mode: 'rotating-qr', code: strtolower($code)));
		$this->now += 30;
		self::assertFalse($codes->verify(windowId: 'win-1', mode: 'rotating-qr', code: $code));
	}//end testTheRotatingCodeLivesTwoSteps()

	/**
	 * A link code holds for the whole window.
	 *
	 * @return void
	 */
	public function testALinkCodeHoldsForTheWholeWindow(): void {
		$codes = $this->codes();
		$code = $codes->current(windowId: 'win-1', mode: 'link');
		$this->now += 900;
		self::assertTrue($codes->verify(windowId: 'win-1', mode: 'link', code: $code));
	}//end testALinkCodeHoldsForTheWholeWindow()

	/**
	 * A learner of the group checks in at the start: present, marked by
	 * self check-in, marked by the learner.
	 *
	 * @return void
	 */
	public function testALearnerChecksInPresent(): void {
		$service = $this->service();
		$outcome = $service->checkIn(windowId: 'win-1', code: $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr'), userId: 'pupil-1');

		self::assertSame(200, $outcome->status);
		self::assertSame('present', $outcome->body['status']);
		$record = $this->records()[0];
		self::assertSame(['ses-1', 'pupil-1', 'present', 'self-check-in', 'pupil-1', 'coh-1', 't1'], [$record['sessionId'], $record['learnerId'], $record['status'], $record['markedVia'], $record['markedBy'], $record['cohortId'], $record['tenant_id']]);
		self::assertNull($this->ranAs);
	}//end testALearnerChecksInPresent()

	/**
	 * After the grace minutes the record is late.
	 *
	 * @return void
	 */
	public function testAfterTheGracePeriodTheRecordIsLate(): void {
		$this->now = self::NOW + 600;
		$service = $this->service();
		$outcome = $service->checkIn(windowId: 'win-1', code: $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr'), userId: 'pupil-2');

		self::assertSame('late', $outcome->body['status']);
	}//end testAfterTheGracePeriodTheRecordIsLate()

	/**
	 * Each refusal answers its reason and writes nothing.
	 *
	 * @return void
	 */
	public function testRefusalsWriteNothing(): void {
		$code = $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr');

		self::assertSame('not-in-group', $this->service()->checkIn(windowId: 'win-1', code: $code, userId: 'stranger')->reason);
		self::assertSame('invalid-code', $this->service()->checkIn(windowId: 'win-1', code: 'AAAAAAAA', userId: 'pupil-1')->reason);
		self::assertSame('window-closed', $this->service(window: ['lifecycle' => 'closed'])->checkIn(windowId: 'win-1', code: $code, userId: 'pupil-1')->reason);
		self::assertSame('window-closed', $this->service(window: ['closesAt' => '2026-09-29T08:31:00+00:00'])->checkIn(windowId: 'win-1', code: $code, userId: 'pupil-1')->reason);
		self::assertSame('not-found', $this->service()->checkIn(windowId: 'nope', code: $code, userId: 'pupil-1')->reason);
		self::assertSame([], $this->records());
	}//end testRefusalsWriteNothing()

	/**
	 * An existing record, a teacher's absent mark, is never overwritten and
	 * no second record is made.
	 *
	 * @return void
	 */
	public function testAnExistingMarkIsNeverOverwritten(): void {
		$service = $this->service();
		$this->store->rows['attendance-record'] = [['id' => 'rec-1', 'sessionId' => 'ses-1', 'learnerId' => 'pupil-1', 'status' => 'absent-unexcused', 'markedVia' => 'teacher']];

		$outcome = $service->checkIn(windowId: 'win-1', code: $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr'), userId: 'pupil-1');

		self::assertSame(409, $outcome->status);
		self::assertSame('already-recorded', $outcome->reason);
		self::assertCount(1, $this->records());
		self::assertSame('absent-unexcused', $this->records()[0]['status']);
	}//end testAnExistingMarkIsNeverOverwritten()

	/**
	 * A portal check-in writes as the pupil and carries the learnerRef.
	 *
	 * @return void
	 */
	public function testAPortalCheckInWritesAsThePupil(): void {
		$service = $this->service();
		$pupil = $this->createMock(IUser::class);
		$pupil->method('getUID')->willReturn('pupil-2');

		$outcome = $service->checkIn(windowId: 'win-1', code: $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr'), userId: 'pupil-2', learnerRef: 'lp-2', runAs: $pupil);

		self::assertSame(200, $outcome->status);
		self::assertSame('pupil-2', $this->ranAs);
		self::assertSame('lp-2', $this->records()[0]['learnerRef']);
	}//end testAPortalCheckInWritesAsThePupil()

	/**
	 * The learner page shows the lesson to a learner of the group only.
	 *
	 * @return void
	 */
	public function testTheLearnerPageShowsTheLessonToTheGroupOnly(): void {
		$shown = $this->service()->show(windowId: 'win-1', userId: 'pupil-1');
		self::assertSame(['title' => 'Wiskunde B', 'open' => true], ['title' => $shown->body['title'], 'open' => $shown->body['open']]);
		self::assertSame(403, $this->service()->show(windowId: 'win-1', userId: 'stranger')->status);
	}//end testTheLearnerPageShowsTheLessonToTheGroupOnly()
	/**
	 * A typed code finds the open window of the learner's own lesson; the
	 * learner's page lists it without the code; a stranger gets nothing.
	 *
	 * @return void
	 */
	public function testACodeAloneFindsTheLearnersOpenWindow(): void {
		$service = $this->service();
		$this->store->rows['check-in-window'][] = ['id' => 'win-9', 'sessionId' => 'ses-9', 'mode' => 'rotating-qr', 'lifecycle' => 'open'];

		$listed = $service->openFor(userId: 'pupil-1');
		self::assertSame(['win-1'], array_column($listed, 'windowId'));
		self::assertArrayNotHasKey('code', $listed[0]);
		self::assertSame([], $service->openFor(userId: 'stranger'));

		self::assertSame('invalid-code', $service->checkInWithCode(code: 'AAAAAAAA', userId: 'pupil-1')->reason);
		$outcome = $service->checkInWithCode(code: $this->codes()->current(windowId: 'win-1', mode: 'rotating-qr'), userId: 'pupil-1');
		self::assertSame(200, $outcome->status);
		self::assertSame('ses-1', $this->records()[0]['sessionId']);
	}//end testACodeAloneFindsTheLearnersOpenWindow()
}//end class
