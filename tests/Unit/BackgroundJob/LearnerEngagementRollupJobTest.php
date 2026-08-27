<?php

/**
 * Learniq LearnerEngagementRollupHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\PointEngagementEvaluator;
use OCA\Learniq\BackgroundJob\LearnerEngagementRollupJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\NullLogger;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearnerEngagementRollupHandler::handle().
 */
class LearnerEngagementRollupJobTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Existing LearnerEngagement row to return from findAll(), or null.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $existingEngagement = null;

	/**
	 * Active streak-milestone PointRule rows to return from findAll().
	 *
	 * @var array<int, array>
	 */
	private array $streakRules = [];

	/**
	 * When true, the evaluator throws. Lets a test put a failing entry ahead
	 * of a good one in the same chunk.
	 *
	 * @var bool
	 */
	private bool $evaluatorThrows = false;

	/**
	 * Evaluator result to return.
	 *
	 * @var array<string,mixed>
	 */
	private array $evaluatorResult = [
		'totalPoints' => 0.0,
		'levelId' => null,
		'currentStreakDays' => 0,
		'longestStreakDays' => 0,
		'lastActivityDate' => null,
	];

	/**
	 * Resolver turning the entity's numeric register/schema ids into slugs.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * Reset the capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$this->savedObjects = [];
		$this->existingEngagement = null;
		$this->streakRules = [];
		$this->evaluatorResult = [
			'totalPoints' => 0.0,
			'levelId' => null,
			'currentStreakDays' => 0,
			'longestStreakDays' => 0,
			'lastActivityDate' => null,
		];

	}//end setUp()

	/**
	 * Stub the resolver the way OpenRegister behaves in production: the entity
	 * carries numeric ids and the resolver turns them into slugs.
	 *
	 * @param string $schemaSlug The slug the resolver resolves the schema id to.
	 *
	 * @return void
	 */
	private function stubResolver(string $schemaSlug): void {
		$this->schemaResolver->method('registerSlug')->willReturn('learniq');
		$this->schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

	}//end stubResolver()

	/**
	 * Build a handler with mocked collaborators.
	 *
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 *
	 * @return LearnerEngagementRollupJob
	 */
	private function makeHandler(DateTime $now): LearnerEngagementRollupJob {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				if ($config['schema'] === 'learner-engagement') {
					return $this->existingEngagement === null ? [] : [$this->existingEngagement];
				}

				if ($config['schema'] === 'point-rule') {
					return $this->streakRules;
				}

				return [];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);

		$evaluator = $this->createMock(PointEngagementEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(
			function () {
				if ($this->evaluatorThrows === true) {
					throw new \RuntimeException('evaluator blew up');
				}

				return $this->evaluatorResult;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);

		return new LearnerEngagementRollupJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			new NullLogger(),
			$objectService,
			$evaluator,
			$timeFactory
		);
	}//end makeHandler()

	/**
	 * Build a mocked ObjectCreatedEvent for a PointAward.
	 *
	 * @param array<string, mixed> $data The PointAward's jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function makeEvent(array $data): array {
		return [
			'learnerId' => ($data['learnerId'] ?? ''),
			'tenantId' => ($data['tenant_id'] ?? ''),
			'sourceKind' => ($data['sourceKind'] ?? ''),
		];
	}//end makeEvent()

	/**
	 * Invoke the protected `runDeferred()` with one entry.
	 *
	 * @param LearnerEngagementRollupJob $job   The job.
	 * @param array<string, mixed>       $entry The buffered entry.
	 *
	 * @return void
	 */
	private function runOne(LearnerEngagementRollupJob $job, array $entry): void {
		$method = new \ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke($job, new DeferredListenerContext(userId: 'learner-1', orgUuid: null, entries: [$entry]));
	}//end runOne()

	/**
	 * Filter savedObjects to those matching a schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array>
	 */
	private function savesFor(string $schema): array {
		return array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === $schema));
	}//end savesFor()

	/**
	 * A new PointAward recomputes and saves LearnerEngagement totals/level/streak.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	/**
	 * An entry with no learnerId is skipped rather than rolled up against an
	 * empty id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testAnEntryWithNoLearnerIdIsSkipped(): void {
		$handler = $this->makeHandler(now: new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')));

		$this->runOne($handler, ['learnerId' => '', 'tenantId' => 'tenant-a', 'sourceKind' => 'enrolment']);

		self::assertCount(
			0,
			$this->savesFor('learner-engagement'),
			'an entry with no learner must not write a row keyed on an empty id'
		);

	}//end testAnEntryWithNoLearnerIdIsSkipped()

	/**
	 * A deferred job is handed a CHUNK. If one entry threw out of the loop,
	 * every later entry would be dropped silently and never retried, so the
	 * per-entry catch is the thing keeping the rest of the chunk alive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
	 */
	public function testOneFailingEntryDoesNotLoseTheRestOfTheChunk(): void {
		$handler = $this->makeHandler(now: new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')));
		$this->evaluatorThrows = true;

		$method = new \ReflectionMethod($handler, 'runDeferred');
		$method->setAccessible(true);

		// Must not escape: the job would be retried wholesale and the entries
		// already written would be written twice.
		$method->invoke(
			$handler,
			new DeferredListenerContext(
				userId: 'learner-1',
				orgUuid: null,
				entries: [
					['learnerId' => 'learner-1', 'tenantId' => 'tenant-a', 'sourceKind' => 'enrolment'],
					['learnerId' => 'learner-2', 'tenantId' => 'tenant-a', 'sourceKind' => 'enrolment'],
				]
			)
		);

		self::assertCount(
			0,
			$this->savesFor('learner-engagement'),
			'both entries failed, and the failure was swallowed per entry rather than thrown'
		);

	}//end testOneFailingEntryDoesNotLoseTheRestOfTheChunk()

	/**
	 * A milestone rule with no `milestoneDays` is skipped rather than treated
	 * as a milestone at day zero, which every streak would cross.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
	 */
	public function testAMilestoneRuleWithoutMilestoneDaysAwardsNothing(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 6];
		$this->streakRules = [['id' => 'rule-1', 'tenant_id' => 'tenant-a', 'points' => 10]];
		$this->evaluatorResult = [
			'totalPoints' => 70.0,
			'levelId' => 'level-silver',
			'currentStreakDays' => 7,
			'longestStreakDays' => 7,
			'lastActivityDate' => '2026-07-15',
		];

		$handler = $this->makeHandler(now: $now);
		$this->runOne(
			$handler,
			$this->makeEvent(['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'enrolment'])
		);

		self::assertCount(
			0,
			$this->savesFor('point-award'),
			'a rule that names no milestone cannot be crossed'
		);

	}//end testAMilestoneRuleWithoutMilestoneDaysAwardsNothing()

	/**
	 * A milestone rule carrying no id is skipped: the bonus award references
	 * its rule, and an award pointing at nothing is worse than no award.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
	 */
	public function testAMilestoneRuleWithoutAnIdAwardsNothing(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 6];
		$this->streakRules = [['tenant_id' => 'tenant-a', 'milestoneDays' => 7, 'points' => 10]];
		$this->evaluatorResult = [
			'totalPoints' => 70.0,
			'levelId' => 'level-silver',
			'currentStreakDays' => 7,
			'longestStreakDays' => 7,
			'lastActivityDate' => '2026-07-15',
		];

		$handler = $this->makeHandler(now: $now);
		$this->runOne(
			$handler,
			$this->makeEvent(['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'enrolment'])
		);

		self::assertCount(0, $this->savesFor('point-award'));

	}//end testAMilestoneRuleWithoutAnIdAwardsNothing()

	public function testNewPointAwardRecomputesLearnerEngagement(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->evaluatorResult = [
			'totalPoints' => 25.0,
			'levelId' => 'level-silver',
			'currentStreakDays' => 2,
			'longestStreakDays' => 2,
			'lastActivityDate' => '2026-07-15',
		];

		$handler = $this->makeHandler(now: $now);

		$award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'enrolment'];
		$this->runOne($handler, $this->makeEvent($award));

		$saves = $this->savesFor('learner-engagement');
		self::assertCount(1, $saves);
		self::assertSame(25.0, $saves[0]['object']['totalPoints']);
		self::assertSame('level-silver', $saves[0]['object']['levelId']);
		self::assertSame(2, $saves[0]['object']['currentStreakDays']);

	}//end testNewPointAwardRecomputesLearnerEngagement()

	/**
	 * A streak crossing from 6 to 7 awards exactly one bonus PointAward for
	 * an active streak-milestone rule with milestoneDays:7.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
	 */
	public function testStreakCrossingAwardsBonusExactlyOnce(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 6];
		$this->evaluatorResult = [
			'totalPoints' => 30.0,
			'levelId' => null,
			'currentStreakDays' => 7,
			'longestStreakDays' => 7,
			'lastActivityDate' => '2026-07-15',
		];
		$this->streakRules = [
			['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
		];

		$handler = $this->makeHandler(now: $now);

		$award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'submission'];
		$this->runOne($handler, $this->makeEvent($award));

		$bonusSaves = $this->savesFor('point-award');
		self::assertCount(1, $bonusSaves);
		self::assertSame('streak-milestone', $bonusSaves[0]['object']['sourceKind']);
		self::assertNull($bonusSaves[0]['object']['sourceObjectId']);
		self::assertSame('rule-streak-7', $bonusSaves[0]['object']['pointRuleId']);
		self::assertSame(20.0, $bonusSaves[0]['object']['points']);

	}//end testStreakCrossingAwardsBonusExactlyOnce()

	/**
	 * The bonus award's own rollup (sourceKind: streak-milestone) does not
	 * re-trigger a further streak-milestone check -- the recursion guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
	 */
	public function testBonusAwardRollupDoesNotReTriggerMilestoneCheck(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 7];
		$this->evaluatorResult = [
			'totalPoints' => 50.0,
			'levelId' => null,
			'currentStreakDays' => 7,
			'longestStreakDays' => 7,
			'lastActivityDate' => '2026-07-15',
		];
		$this->streakRules = [
			['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
		];

		$handler = $this->makeHandler(now: $now);

		// This event's OWN sourceKind is streak-milestone -- the recursion guard.
		$award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'streak-milestone'];
		$this->runOne($handler, $this->makeEvent($award));

		self::assertCount(0, $this->savesFor('point-award'));
		self::assertCount(1, $this->savesFor('learner-engagement'));

	}//end testBonusAwardRollupDoesNotReTriggerMilestoneCheck()

	/**
	 * No forward streak progress (equal or lower) never awards a bonus.
	 *
	 * @return void
	 */
	public function testNoForwardStreakProgressAwardsNoBonus(): void {
		$now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->existingEngagement = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'currentStreakDays' => 7];
		$this->evaluatorResult = [
			'totalPoints' => 10.0,
			'levelId' => null,
			'currentStreakDays' => 7,
			'longestStreakDays' => 7,
			'lastActivityDate' => '2026-07-15',
		];
		$this->streakRules = [
			['id' => 'rule-streak-7', 'points' => 20, 'milestoneDays' => 7],
		];

		$handler = $this->makeHandler(now: $now);

		$award = ['learnerId' => 'learner-1', 'tenant_id' => 'tenant-a', 'sourceKind' => 'grade-entry'];
		$this->runOne($handler, $this->makeEvent($award));

		self::assertCount(0, $this->savesFor('point-award'));

	}//end testNoForwardStreakProgressAwardsNoBonus()

}//end class
