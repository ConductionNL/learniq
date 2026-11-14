<?php

/**
 * Tests for PortalHourDayWrite.
 *
 * Every test reads the week back from the store, so a day that was answered
 * as saved but never landed on the week, or landed on another student's
 * week, fails.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\HourDayLines;
use OCA\Learniq\Service\Portal\PortalHourDayWrite;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * A student writes one day of her week, keeps it or sends it, and is refused
 * for another student's placement or a week that is no longer hers to change.
 */
class PortalHourDayWriteTest extends TestCase {

	private const LEARNER = 'lp-milan';

	private const PLACEMENT = 'ee040020-0000-4000-8000-000000000001';

	private const OTHER_PLACEMENT = 'ee040020-0000-4000-8000-000000000002';

	private const WEEK = 'ee040040-0000-4000-8000-000000000001';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Monday 5 October 2026 from 08.00 to 16.30 with 30 minutes break.
	 *
	 * @var array<string, mixed>
	 */
	private const MONDAY = [
		'bpvPlacementId' => self::PLACEMENT,
		'date' => '2026-10-05',
		'startTime' => '08:00',
		'endTime' => '16:30',
		'breakMinutes' => '30',
		'description' => 'Een cv-ketel vervangen.',
		'werkprocesCodes' => ['B1-K1-W2', ' ', 'B1-K1-W2', 'B1-K2-W1'],
	];

	/**
	 * The service over a store holding Milan's placement, another student's
	 * placement, and optionally a stored week 41 in the given state.
	 *
	 * @param string|null $weekState The stored week's lifecycle, or null for no week.
	 *
	 * @return PortalHourDayWrite
	 */
	private function days(?string $weekState = null): PortalHourDayWrite {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'bpv-placement' => [
				['id' => self::PLACEMENT, 'learnerRef' => self::LEARNER, 'tenant_id' => 't-1'],
				['id' => self::OTHER_PLACEMENT, 'learnerRef' => 'lp-other', 'tenant_id' => 't-1'],
			],
			'bpv-hour-week' => [],
		];
		if ($weekState !== null) {
			$this->store->rows['bpv-hour-week'][] = [
				'id' => self::WEEK,
				'bpvPlacementId' => self::PLACEMENT,
				'learnerRef' => self::LEARNER,
				'isoWeek' => '2026-W41',
				'hoursSubmitted' => 4,
				'days' => [
					['date' => '2026-10-05', 'startTime' => '08:00', 'endTime' => '12:00', 'breakMinutes' => 0, 'hours' => 4, 'returnedQuestion' => 'Klopt maandag?'],
					['date' => '2026-10-06', 'startTime' => '09:00', 'endTime' => '12:00', 'breakMinutes' => 0, 'hours' => 3],
				],
				'lifecycle' => $weekState,
				'tenant_id' => 't-1',
			];
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => $this->store->save((string)$schema, $object, $uuid, false)
		);

		return new PortalHourDayWrite(objectService: $objectService);
	}//end days()

	/**
	 * A first day of a week creates that week as a draft, with the day's
	 * hours worked out (8.5 hours less a 30 minute break is 8) and the week's
	 * hours as their sum; the work processes are kept once each.
	 *
	 * @return void
	 */
	public function testAFirstDayCreatesTheWeekAsADraft(): void {
		$outcome = $this->days()->write(learnerRef: self::LEARNER, body: self::MONDAY + ['send' => 'keep']);

		self::assertSame(200, $outcome->status);
		self::assertSame('draft', $outcome->body['lifecycle']);
		self::assertSame('2026-W41', $outcome->body['isoWeek']);
		self::assertCount(1, $this->store->rows['bpv-hour-week']);
		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame(self::PLACEMENT, $week['bpvPlacementId']);
		self::assertSame(self::LEARNER, $week['learnerRef']);
		self::assertSame('draft', $week['lifecycle']);
		self::assertSame(8.0, $week['hoursSubmitted']);
		self::assertSame(
			[
				'date' => '2026-10-05',
				'startTime' => '08:00',
				'endTime' => '16:30',
				'breakMinutes' => 30,
				'hours' => 8.0,
				'werkprocesCodes' => ['B1-K1-W2', 'B1-K2-W1'],
				'description' => 'Een cv-ketel vervangen.',
			],
			$week['days'][0]
		);
		self::assertArrayNotHasKey('submittedAt', $week);
		self::assertSame($week['id'], $outcome->body['hourWeekId']);
	}//end testAFirstDayCreatesTheWeekAsADraft()

	/**
	 * Sending hands the week to the trainer: it becomes submitted with the moment she sent it.
	 *
	 * @return void
	 */
	public function testSendingSubmitsTheWeek(): void {
		$outcome = $this->days()->write(learnerRef: self::LEARNER, body: self::MONDAY + ['send' => PortalHourDayWrite::SEND]);

		self::assertSame(200, $outcome->status);
		self::assertSame('submitted', $outcome->body['lifecycle']);
		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame('submitted', $week['lifecycle']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $week['submittedAt']);
	}//end testSendingSubmitsTheWeek()

	/**
	 * On a week her trainer sent back she replaces the day the question was
	 * about: the other day stays, the question goes with the old line, the
	 * hours are summed again, and sending puts the week back with the trainer.
	 *
	 * @return void
	 */
	public function testAReturnedWeekTakesTheChangedDayAndGoesBack(): void {
		$outcome = $this->days(weekState: 'rejected')->write(learnerRef: self::LEARNER, body: self::MONDAY + ['send' => 'send']);

		self::assertSame(200, $outcome->status);
		self::assertSame(self::WEEK, $outcome->body['hourWeekId']);
		self::assertCount(1, $this->store->rows['bpv-hour-week']);
		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame('submitted', $week['lifecycle']);
		self::assertSame(['2026-10-05', '2026-10-06'], array_column($week['days'], 'date'));
		self::assertSame('16:30', $week['days'][0]['endTime']);
		self::assertArrayNotHasKey('returnedQuestion', $week['days'][0]);
		self::assertSame(3.0, $week['days'][1]['hours']);
		self::assertSame(11.0, $week['hoursSubmitted']);
	}//end testAReturnedWeekTakesTheChangedDayAndGoesBack()

	/**
	 * Keeping a draft for later adds a day and leaves the week a draft.
	 *
	 * @return void
	 */
	public function testKeepingADraftLeavesItADraft(): void {
		$body = ['date' => '2026-10-07', 'startTime' => '07:30', 'endTime' => '12:00', 'breakMinutes' => ''] + self::MONDAY;
		$outcome = $this->days(weekState: 'draft')->write(learnerRef: self::LEARNER, body: $body);

		self::assertSame(200, $outcome->status);
		$week = $this->store->rows['bpv-hour-week'][0];
		self::assertSame('draft', $week['lifecycle']);
		self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07'], array_column($week['days'], 'date'));
		self::assertSame(0, $week['days'][2]['breakMinutes']);
		self::assertSame(11.5, $week['hoursSubmitted']);
	}//end testKeepingADraftLeavesItADraft()

	/**
	 * A week that waits for the trainer or is approved does not change.
	 *
	 * @return void
	 */
	public function testAWeekWithTheTrainerOrApprovedIsRefused(): void {
		foreach (['submitted', 'approved', 'corrected'] as $state) {
			$outcome = $this->days(weekState: $state)->write(learnerRef: self::LEARNER, body: self::MONDAY + ['send' => 'send']);

			self::assertSame(409, $outcome->status, $state);
			self::assertSame('hour-week-with-trainer', $outcome->reason);
			self::assertSame([], $this->store->saves, $state);
			self::assertSame(4, $this->store->rows['bpv-hour-week'][0]['hoursSubmitted']);
		}
	}//end testAWeekWithTheTrainerOrApprovedIsRefused()

	/**
	 * Another student's placement is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherStudentsPlacementIsRefused(): void {
		$outcome = $this->days()->write(learnerRef: self::LEARNER, body: ['bpvPlacementId' => self::OTHER_PLACEMENT] + self::MONDAY);

		self::assertSame(403, $outcome->status);
		self::assertSame('hour-day-not-your-placement', $outcome->reason);
		self::assertSame([], $this->store->rows['bpv-hour-week']);
		self::assertSame([], $this->store->saves);
	}//end testAnotherStudentsPlacementIsRefused()

	/**
	 * Without a placement, without a learner, or with a day that is not a
	 * real date, the request is incomplete; nothing is read for a week.
	 *
	 * @return void
	 */
	public function testAnIncompleteRequestIsRefused(): void {
		$cases = [
			[self::LEARNER, ['bpvPlacementId' => ''] + self::MONDAY],
			[self::LEARNER, ['date' => '2026-02-30'] + self::MONDAY],
			[self::LEARNER, ['date' => '5 oktober'] + self::MONDAY],
			['', self::MONDAY],
		];
		foreach ($cases as [$learnerRef, $body]) {
			$outcome = $this->days()->write(learnerRef: $learnerRef, body: $body);

			self::assertSame(422, $outcome->status);
			self::assertSame('hour-day-incomplete', $outcome->reason);
			self::assertSame([], $this->store->saves);
		}
	}//end testAnIncompleteRequestIsRefused()

	/**
	 * Times that cannot be read, or a break as long as the day, are refused
	 * with HourDayLines' reason and nothing is written.
	 *
	 * @return void
	 */
	public function testADayThatCannotBeReadIsRefused(): void {
		$cases = [
			HourDayLines::TIMES_INVALID => ['endTime' => '07:00'] + self::MONDAY,
			HourDayLines::BREAK_TOO_LONG => ['breakMinutes' => 600] + self::MONDAY,
		];
		foreach ($cases as $reason => $body) {
			$outcome = $this->days()->write(learnerRef: self::LEARNER, body: $body);

			self::assertSame(422, $outcome->status, $reason);
			self::assertSame($reason, $outcome->reason);
			self::assertSame([], $this->store->saves);
		}
	}//end testADayThatCannotBeReadIsRefused()
}//end class
