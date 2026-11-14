<?php

/**
 * Unit tests for PortalPermissionAnswer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Service\Portal\PortalPermissionAnswer;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A guardian answers for her own child only, before the deadline, at or above
 * the school's assurance floor; one answer per child per request.
 *
 * @covers \OCA\Learniq\Service\Portal\PortalPermissionAnswer
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class PortalPermissionAnswerTest extends TestCase {

	private const GUARDIAN = 'ee050010-0000-4000-8000-000000000001';

	private const OTHER_GUARDIAN = 'ee050010-0000-4000-8000-000000000002';

	private const VERA = 'ee050020-0000-4000-8000-000000000001';

	private const CLASSMATE = 'ee050020-0000-4000-8000-000000000002';

	private const REQUEST = 'ee050030-0000-4000-8000-000000000001';

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private RegisterFaithfulStore $store;

	/**
	 * The subject over a store with one open request for Vera and a classmate.
	 *
	 * @param string $floor      The school's assurance floor.
	 * @param array  $request    Overrides on the request.
	 * @param bool   $saveThrows Whether the write fails.
	 *
	 * @return PortalPermissionAnswer
	 */
	private function answers(string $floor = 'basic', array $request = [], bool $saveThrows = false): PortalPermissionAnswer {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'permission-request' => [
				array_merge(
					[
						'id' => self::REQUEST,
						'title' => 'Schoolreisje Openluchtmuseum',
						'happensOn' => '2026-10-22',
						'respondBy' => '2026-10-16T23:59:00+02:00',
						'learnerRefs' => [self::VERA, self::CLASSMATE],
						'treatSilenceAsRefusal' => true,
						'lifecycle' => 'open',
						'tenant_id' => self::TENANT,
					],
					$request
				),
			],
			'permission-response' => [],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($saveThrows): object {
				if ($saveThrows === true) {
					throw new RuntimeException('SQLSTATE secret');
				}

				return $this->store->save((string)$schema, $object, $uuid ?? ($object['id'] ?? null));
			}
		);

		$profiles = $this->createMock(LearnerRefResolverInterface::class);
		$profiles->method('byRef')->willReturnCallback(
			static fn (string $learnerRef): ?array => [
				self::VERA => ['id' => self::VERA, 'guardianRefs' => [self::GUARDIAN]],
				self::CLASSMATE => ['id' => self::CLASSMATE, 'guardianRefs' => [self::OTHER_GUARDIAN]],
			][$learnerRef] ?? null
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('learniq', PortalPermissionAnswer::MIN_ASSURANCE_KEY, 'basic')->willReturn($floor);

		return new PortalPermissionAnswer(objectService: $objectService, profiles: $profiles, appConfig: $config, logger: new NullLogger());
	}//end answers()

	/**
	 * Before the deadline.
	 *
	 * @return DateTimeImmutable
	 */
	private static function beforeDeadline(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-14T10:00:00+02:00');
	}//end beforeDeadline()

	/**
	 * The answer form for Vera.
	 *
	 * @param string $answer The answer.
	 *
	 * @return array<string, string>
	 */
	private static function body(string $answer = 'yes', string $learnerRef = self::VERA): array {
		return ['permissionRequestId' => self::REQUEST, 'learnerRef' => $learnerRef, 'answer' => $answer];
	}//end body()

	/**
	 * Fatima answers yes for Vera: one response, naming her, the moment and her assurance.
	 *
	 * @return void
	 */
	public function testAGuardianAnswersForHerOwnChild(): void {
		$outcome = $this->answers()->answer(guardianRef: self::GUARDIAN, trust: 'substantial', body: self::body(), now: self::beforeDeadline());

		self::assertSame(200, $outcome->status);
		self::assertSame(['permissionRequestId' => self::REQUEST, 'learnerRef' => self::VERA, 'answer' => 'yes', 'assuranceLevel' => 'substantial'], $outcome->body);
		self::assertCount(1, $this->store->saves);
		self::assertSame('permission-response', $this->store->saves[0]['schema']);
		self::assertSame(
			[
				'permissionRequestId' => self::REQUEST,
				'learnerRef' => self::VERA,
				'answer' => 'yes',
				'answeredByRef' => self::GUARDIAN,
				'answeredAt' => '2026-10-14T10:00:00+02:00',
				'assuranceLevel' => 'substantial',
				'tenant_id' => self::TENANT,
			],
			$this->store->saves[0]['object']
		);
	}//end testAGuardianAnswersForHerOwnChild()

	/**
	 * A classmate is not hers to answer for, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnotherChildIsRefused(): void {
		$outcome = $this->answers()->answer(guardianRef: self::GUARDIAN, trust: 'substantial', body: self::body(learnerRef: self::CLASSMATE), now: self::beforeDeadline());

		self::assertSame(403, $outcome->status);
		self::assertSame('not-your-child', $outcome->reason);
		self::assertSame([], $this->store->saves);
	}//end testAnotherChildIsRefused()

	/**
	 * Her own child, but not one the request covers, is refused too.
	 *
	 * @return void
	 */
	public function testAChildTheRequestDoesNotCoverIsRefused(): void {
		$outcome = $this->answers(request: ['learnerRefs' => [self::CLASSMATE]])->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body(), now: self::beforeDeadline());

		self::assertSame(403, $outcome->status);
		self::assertSame([], $this->store->saves);
	}//end testAChildTheRequestDoesNotCoverIsRefused()

	/**
	 * A closed request takes no answer, and says so.
	 *
	 * @return void
	 */
	public function testAClosedRequestIsRefused(): void {
		$outcome = $this->answers(request: ['lifecycle' => 'closed'])->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body(), now: self::beforeDeadline());

		self::assertSame(409, $outcome->status);
		self::assertSame('request_closed', $outcome->body['error']);
		self::assertSame('This request is closed. It no longer takes an answer.', $outcome->body['message']);
		self::assertSame([], $this->store->saves);
	}//end testAClosedRequestIsRefused()

	/**
	 * An answer after the deadline is refused as closed.
	 *
	 * @return void
	 */
	public function testAnAnswerAfterTheDeadlineIsRefused(): void {
		$outcome = $this->answers()->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body(), now: new DateTimeImmutable('2026-10-17T08:00:00+02:00'));

		self::assertSame(409, $outcome->status);
		self::assertSame('request-closed', $outcome->reason);
	}//end testAnAnswerAfterTheDeadlineIsRefused()

	/**
	 * Below the school's floor the answer is refused with the level it needs.
	 *
	 * @return void
	 */
	public function testAnAnswerBelowTheFloorNamesTheRequiredLevel(): void {
		$outcome = $this->answers(floor: 'substantial')->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body(), now: self::beforeDeadline());

		self::assertSame(403, $outcome->status);
		self::assertSame(['error' => 'assurance_too_low', 'required' => 'substantial'], $outcome->body);
		self::assertSame([], $this->store->saves);
	}//end testAnAnswerBelowTheFloorNamesTheRequiredLevel()

	/**
	 * An unknown floor value falls back to basic, so a school account answers.
	 *
	 * @return void
	 */
	public function testAnUnknownFloorFallsBackToBasic(): void {
		$outcome = $this->answers(floor: 'nonsense')->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body('no'), now: self::beforeDeadline());

		self::assertSame(200, $outcome->status);
		self::assertSame('basic', $outcome->body['assuranceLevel']);
		self::assertSame('no', $this->store->saves[0]['object']['answer']);
	}//end testAnUnknownFloorFallsBackToBasic()

	/**
	 * An incomplete or unknown answer is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnIncompleteAnswerIsRefused(): void {
		$answers = $this->answers();
		foreach ([['answer' => 'maybe'], ['learnerRef' => ''], ['permissionRequestId' => null]] as $override) {
			$outcome = $answers->answer(guardianRef: self::GUARDIAN, trust: 'low', body: array_merge(self::body(), $override), now: self::beforeDeadline());
			self::assertSame(422, $outcome->status, json_encode($override));
		}

		self::assertSame(422, $answers->answer(guardianRef: '', trust: 'low', body: self::body(), now: self::beforeDeadline())->status);
		self::assertSame([], $this->store->saves);
	}//end testAnIncompleteAnswerIsRefused()

	/**
	 * An unknown request is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownRequestIsNotFound(): void {
		$outcome = $this->answers()->answer(guardianRef: self::GUARDIAN, trust: 'low', body: array_merge(self::body(), ['permissionRequestId' => 'ee050030-0000-4000-8000-000000000099']), now: self::beforeDeadline());

		self::assertSame(404, $outcome->status);
	}//end testAnUnknownRequestIsNotFound()

	/**
	 * A second answer replaces the first: one response per child per request.
	 *
	 * @return void
	 */
	public function testASecondAnswerReplacesTheFirst(): void {
		$answers = $this->answers();
		$answers->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body('yes'), now: self::beforeDeadline());
		$firstId = $this->store->saves[0]['uuid'] ?? null;
		$this->store->rows['permission-response'] = [array_merge($this->store->saves[0]['object'], ['id' => 'ee050040-0000-4000-8000-000000000001'])];

		$answers->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body('no'), now: self::beforeDeadline());

		self::assertNull($firstId);
		self::assertSame('ee050040-0000-4000-8000-000000000001', $this->store->saves[1]['object']['id']);
		self::assertSame('no', $this->store->saves[1]['object']['answer']);
	}//end testASecondAnswerReplacesTheFirst()

	/**
	 * A failing write is a 502 without the internal message.
	 *
	 * @return void
	 */
	public function testAFailingWriteLeaksNothing(): void {
		$outcome = $this->answers(saveThrows: true)->answer(guardianRef: self::GUARDIAN, trust: 'low', body: self::body(), now: self::beforeDeadline());

		self::assertSame(502, $outcome->status);
		self::assertSame(['error' => 'downstream_error'], $outcome->body);
	}//end testAFailingWriteLeaksNothing()
}//end class
