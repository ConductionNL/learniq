<?php

/**
 * Learniq ReportCardVisibilityGuard unit tests.
 *
 * @category Tests
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
 *
 * @spec openspec/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
 * @spec openspec/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Lifecycle\ReportCardVisibilityGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportCardVisibilityGuard (finalised -> published-to-parents).
 */
class ReportCardVisibilityGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose ObjectService::findAll(schema=grade-entry) resolves
	 * from a fixed id => visibleFrom map, and whose "now" is fixed.
	 *
	 * @param array<string,string|null> $visibleFromById GradeEntry id => visibleFrom (or missing = unresolvable).
	 * @param DateTime $now The current moment.
	 *
	 * @return ReportCardVisibilityGuard
	 */
	private function makeGuard(array $visibleFromById, DateTime $now): ReportCardVisibilityGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($visibleFromById) {
				if ($config['filters']['schema'] !== 'grade-entry') {
					return [];
				}

				$id = $config['ids'][0] ?? null;
				if ($id === null || array_key_exists($id, $visibleFromById) === false) {
					return [];
				}

				return [['id' => $id, 'visibleFrom' => $visibleFromById[$id]]];
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);
		$timeFactory->method('now')->willReturn(DateTimeImmutable::createFromMutable($now));

		return new ReportCardVisibilityGuard($objectService, $timeFactory, $this->createMock(LoggerInterface::class));
	}//end makeGuard()

	/**
	 * Every contributing GradeEntry's visibleFrom has already passed -> allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
	 */
	public function testAllVisibleFromPassedAllowsPublish(): void {
		$now = new DateTime('2026-07-13T12:00:00+00:00');
		$guard = $this->makeGuard(
			visibleFromById: ['entry-1' => '2026-07-01T00:00:00+00:00', 'entry-2' => '2026-07-10T00:00:00+00:00'],
			now: $now
		);

		$object = [
				'id' => 'card-1',
				'subjectGrades' => [
					['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']],
					['curriculumPlanId' => 'plan-2', 'sourceGradeEntryIds' => ['entry-2']],
				],
				'lifecycle' => 'published-to-parents',
			];

		self::assertAllowed($guard->check($object, 'publishToParents', ''));

	}//end testAllVisibleFromPassedAllowsPublish()

	/**
	 * One contributing GradeEntry's visibleFrom is still in the future -> blocked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
	 */
	public function testFutureVisibleFromBlocksPublish(): void {
		$now = new DateTime('2026-07-13T12:00:00+00:00');
		$guard = $this->makeGuard(
			visibleFromById: ['entry-1' => '2026-07-01T00:00:00+00:00', 'entry-2' => '2099-01-01T00:00:00+00:00'],
			now: $now
		);

		$object = [
				'id' => 'card-1',
				'subjectGrades' => [
					['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']],
					['curriculumPlanId' => 'biologie', 'sourceGradeEntryIds' => ['entry-2']],
				],
				'lifecycle' => 'published-to-parents',
			];

		self::assertDenied($guard->check($object, 'publishToParents', ''));

	}//end testFutureVisibleFromBlocksPublish()

	/**
	 * A null visibleFrom on a source GradeEntry blocks (fail closed).
	 *
	 * @return void
	 */
	public function testNullVisibleFromBlocksPublish(): void {
		$now = new DateTime('2026-07-13T12:00:00+00:00');
		$guard = $this->makeGuard(visibleFromById: ['entry-1' => null], now: $now);

		$object = [
				'id' => 'card-1',
				'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']]],
				'lifecycle' => 'published-to-parents',
			];

		self::assertDenied($guard->check($object, 'publishToParents', ''));

	}//end testNullVisibleFromBlocksPublish()

	/**
	 * An unresolvable (deleted/missing) source GradeEntry blocks (fail closed).
	 *
	 * @return void
	 */
	public function testUnresolvableSourceGradeEntryBlocksPublish(): void {
		$now = new DateTime('2026-07-13T12:00:00+00:00');
		$guard = $this->makeGuard(visibleFromById: [], now: $now);

		$object = [
				'id' => 'card-1',
				'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-missing']]],
				'lifecycle' => 'published-to-parents',
			];

		self::assertDenied($guard->check($object, 'publishToParents', ''));

	}//end testUnresolvableSourceGradeEntryBlocksPublish()

	/**
	 * A subject row with no sourceGradeEntryIds contributes nothing to block on.
	 *
	 * @return void
	 */
	public function testEmptySourceGradeEntryIdsDoesNotBlock(): void {
		$now = new DateTime('2026-07-13T12:00:00+00:00');
		$guard = $this->makeGuard(visibleFromById: [], now: $now);
		$object = [
				'id' => 'card-1',
				'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => []]],
				'lifecycle' => 'published-to-parents',
			];

		self::assertAllowed($guard->check($object, 'publishToParents', ''));

	}//end testEmptySourceGradeEntryIdsDoesNotBlock()

	/**
	 * A guard over a recording store: every findAll config is kept, every log
	 * line too, and "now" is read from a counted time source.
	 *
	 * @param array<string,mixed>  $results GradeEntry id => what findAll returns for it.
	 * @param \ArrayObject<int,mixed> $calls   Filled with every findAll config and log line.
	 *
	 * @return ReportCardVisibilityGuard
	 */
	private function recordingGuard(array $results, \ArrayObject $calls): ReportCardVisibilityGuard {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($results, $calls): array {
				$calls[] = ['findAll', $config];
				return ($results[$config['ids'][0]] ?? []);
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-07-13T12:00:00+00:00'));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		return new ReportCardVisibilityGuard($objectService, $timeFactory, $logger);
	}//end recordingGuard()

	/**
	 * Unusable rows and ids are skipped, entries are fetched in order with the
	 * exact query, the scan stops at the first embargoed one, and the block is
	 * logged with its plan and entry and denied with the exact reason.
	 *
	 * @return void
	 */
	public function testTheScanOrderQueriesAndBlockAreExact(): void {
		$calls = new \ArrayObject();
		$guard = $this->recordingGuard(
			results: [
				'e1' => [['id' => 'e1', 'visibleFrom' => '2026-07-01T00:00:00+00:00']],
				'e2' => [['id' => 'e2', 'visibleFrom' => '2099-01-01T00:00:00+00:00']],
				'e3' => [['id' => 'e3', 'visibleFrom' => '2026-07-01T00:00:00+00:00']],
			],
			calls: $calls
		);

		$verdict = $guard->check(
			[
				'uuid' => 'card-9',
				'tenant_id' => 't1',
				'subjectGrades' => [
					'not a row',
					['curriculumPlanId' => 'plan-0', 'sourceGradeEntryIds' => 'not a list'],
					['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => [7, '', 'e1']],
					['sourceGradeEntryIds' => ['e2', 'e3']],
				],
			],
			'publishToParents',
			''
		);

		self::assertFalse($verdict->isAllowed());
		self::assertSame('One or more grades on this report card are not visible yet, so it can not be published to parents.', $verdict->getMessage());
		$query = static fn (string $id): array => [
			'findAll',
			['ids' => [$id], 'filters' => ['tenant_id' => 't1', 'register' => 'learniq', 'schema' => 'grade-entry'], 'limit' => 1],
		];
		self::assertSame(
			[
				$query('e1'),
				$query('e2'),
				[
					'info',
					'[ReportCardVisibilityGuard] ReportCard {id} blocked — subject {plan} sourceGradeEntry {entry} visibleFrom has not passed.',
					['id' => 'card-9', 'plan' => '?', 'entry' => 'e2'],
				],
			],
			$calls->getArrayCopy()
		);
	}//end testTheScanOrderQueriesAndBlockAreExact()

	/**
	 * Without a tenant the query carries no tenant filter; a missing entry is
	 * logged and blocks; an entity result is read through jsonSerialize.
	 *
	 * @return void
	 */
	public function testAMissingEntryWarnsAndAnEntityIsSerialised(): void {
		$entity = new class () implements \JsonSerializable {
			/**
			 * The stored GradeEntry.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['visibleFrom' => '2026-07-01T00:00:00+00:00'];
			}//end jsonSerialize()
		};
		$calls = new \ArrayObject();
		$guard = $this->recordingGuard(results: ['e1' => [$entity]], calls: $calls);

		$verdict = $guard->check(['id' => 'card-1', 'subjectGrades' => [['curriculumPlanId' => 'p', 'sourceGradeEntryIds' => ['e1', 'gone']]]], 'publishToParents', '');

		self::assertFalse($verdict->isAllowed());
		$copy = $calls->getArrayCopy();
		self::assertSame(['ids' => ['e1'], 'filters' => ['register' => 'learniq', 'schema' => 'grade-entry'], 'limit' => 1], $copy[0][1]);
		self::assertSame(
			['warning', '[ReportCardVisibilityGuard] GradeEntry {id} not found — treating visibleFrom as not-yet-passed (fail closed).', ['id' => 'gone']],
			$copy[2]
		);
		self::assertSame(['id' => 'card-1', 'plan' => 'p', 'entry' => 'gone'], $copy[3][2]);
	}//end testAMissingEntryWarnsAndAnEntityIsSerialised()

	/**
	 * An empty or unreadable visibleFrom blocks; a card whose subject grades are
	 * not a list, or with no rows, is allowed.
	 *
	 * @return void
	 */
	public function testUnreadableDatesBlockAndAMalformedCardPasses(): void {
		foreach (['', 'not a date', 42] as $visibleFrom) {
			$guard = $this->recordingGuard(results: ['e1' => [['visibleFrom' => $visibleFrom]]], calls: new \ArrayObject());
			$verdict = $guard->check(['subjectGrades' => [['sourceGradeEntryIds' => ['e1']]]], 'publishToParents', '');
			self::assertFalse($verdict->isAllowed(), var_export($visibleFrom, true));
		}

		$calls = new \ArrayObject();
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->expects(self::never())->method('now');
		$guard = new ReportCardVisibilityGuard($this->createMock(ObjectService::class), $timeFactory, $this->createMock(LoggerInterface::class));
		self::assertTrue($guard->check(['subjectGrades' => 'nope'], 'publishToParents', '')->isAllowed());

		$guard = $this->recordingGuard(results: [], calls: $calls);
		self::assertTrue($guard->check(['subjectGrades' => []], 'publishToParents', '')->isAllowed());
		self::assertSame([], $calls->getArrayCopy());
	}//end testUnreadableDatesBlockAndAMalformedCardPasses()
}//end class
