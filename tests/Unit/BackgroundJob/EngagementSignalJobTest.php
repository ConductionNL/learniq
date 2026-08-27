<?php

/**
 * Learniq EngagementSignalHandler unit tests.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Analytics\EngagementScoreEvaluator;
use OCA\Learniq\BackgroundJob\EngagementSignalJob;
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
 * Tests for EngagementSignalHandler::handle() on ObjectCreatedEvent<XapiStatement>.
 */
class EngagementSignalJobTest extends TestCase {

	/**
	 * In-memory fake OR datastore, keyed by schema slug. Persists across
	 * multiple handle() calls within one test so idempotency can be
	 * exercised.
	 *
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $db = [];

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Resolver turning the entity's numeric register/schema ids into slugs.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $schemaResolver;

	/**
	 * When set, the evaluator throws for entries carrying this courseId.
	 *
	 * Lets a test put a FAILING entry in front of a good one in the same
	 * chunk, which is the only way to show the per-entry catch is what keeps
	 * the rest of the chunk alive.
	 *
	 * @var string|null
	 */
	private ?string $evaluatorThrowsFor = null;

	/**
	 * Reset fixtures before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = [];
		$this->savedObjects = [];
		$this->evaluatorThrowsFor = null;
		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);

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
	 * Build a handler backed by an ObjectService stub over $this->db and a
	 * mocked EngagementScoreEvaluator returning a fixed result.
	 *
	 * @param array{timeOnTaskMinutes: float, lastActivityAt: string|null, score: int} $evaluated Result the mocked evaluator returns.
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 *
	 * @return EngagementSignalJob
	 */
	private function makeHandler(array $evaluated, DateTime $now): EngagementSignalJob {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema === 'cohort') {
					foreach (($this->db['cohort'] ?? []) as $cohort) {
						if (($cohort['id'] ?? null) === $id) {
							return OrEntityFactory::make($cohort, 'cohort');
						}
					}
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$schema = $config['schema'];
				$records = $this->db[$schema] ?? [];
				$filters = $config['filters'] ?? [];

				$matched = array_values(
					array_filter(
						$records,
						static function (array $rec) use ($filters) {
							foreach ($filters as $key => $value) {
								if (($rec[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

				if (isset($config['limit']) === true) {
					$matched = array_slice($matched, 0, (int)$config['limit']);
				}

				return $matched;
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$register = (string)$register;
				$schema = (string)$schema;

				if (isset($object['id']) === false) {
					$object['id'] = $schema . '-auto-' . (count($this->db[$schema] ?? []) + 1);
				}

				$this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

				$existingIndex = null;
				foreach (($this->db[$schema] ?? []) as $index => $rec) {
					if (($rec['id'] ?? null) === $object['id']) {
						$existingIndex = $index;
						break;
					}
				}

				if ($existingIndex !== null) {
					$this->db[$schema][$existingIndex] = $object;
				} else {
					$this->db[$schema][] = $object;
				}

				return OrEntityFactory::make($object, $schema, $register);
			}
		);

		$evaluator = $this->createMock(EngagementScoreEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(
			function (...$args) use ($evaluated) {
				if ($this->evaluatorThrowsFor !== null) {
					foreach ($args as $arg) {
						if ($arg === $this->evaluatorThrowsFor) {
							throw new \RuntimeException('evaluator blew up for ' . $this->evaluatorThrowsFor);
						}
					}
				}

				return $evaluated;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn($now);
		$timeFactory->method('now')->willReturn(DateTimeImmutable::createFromMutable($now));

		return new EngagementSignalJob(
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
	 * Seed a record into the fake datastore.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $record Record data.
	 *
	 * @return void
	 */
	private function seed(string $schema, array $record): void {
		$this->db[$schema][] = $record;

	}//end seed()

	/**
	 * Build a mocked ObjectCreatedEvent<XapiStatement>.
	 *
	 * @param array<string, mixed> $data The XapiStatement jsonSerialize() payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function entryFrom(array $data): array {
		return [
			'learnerId' => ($data['verified_actor_id'] ?? ''),
			'courseId' => ($data['courseId'] ?? ''),
			'tenantId' => ($data['tenant_id'] ?? ''),
		];
	}//end entryFrom()

	/**
	 * Invoke the protected `runDeferred()` with one entry.
	 *
	 * @param EngagementSignalJob  $job   The job.
	 * @param array<string, mixed> $entry The buffered entry.
	 *
	 * @return void
	 */
	private function runOne(EngagementSignalJob $job, array $entry): void {
		$method = new \ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke($job, new DeferredListenerContext(userId: 'learner-1', orgUuid: null, entries: [$entry]));
	}//end runOne()

	/**
	 * (unused in the job test; kept for the fixture helpers below)
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return mixed The event.
	 */
	private function makeXapiEvent(array $data): ObjectCreatedEvent {
		$objectEntity = OrEntityFactory::make($data, '1280', '9');
		$this->stubResolver('xapi-statement');

		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);

		return $event;
	}//end makeXapiEvent()

	/**
	 * Fetch every saveObject() call recorded for a given schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(string $schema): array {
		return array_values(
			array_map(
				static fn (array $s) => $s['object'],
				array_filter($this->savedObjects, static fn (array $s) => $s['schema'] === $schema)
			)
		);

	}//end savedFor()

	/**
	 * The score recompute always runs and persists an EngagementScore, even
	 * with no active threshold.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-engagementscore-objects-persist-and-recompute-from-xapi-activity
	 */
	public function testScoreRecomputeAlwaysRuns(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 12.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 55],
			now: $now
		);

		$this->runOne($handler, $this->entryFrom(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		$scores = $this->savedFor('engagement-score');
		self::assertCount(1, $scores);
		self::assertSame(55, $scores[0]['score']);
		self::assertSame(0, count($this->savedFor('engagement-risk-flag')));

	}//end testScoreRecomputeAlwaysRuns()

	/**
	 * A learner whose score falls below an active engagement-score-below
	 * threshold gets a flag on first crossing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testFlagCreatedOnFirstCrossing(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'engagement-risk-threshold',
			[
				'id' => 'threshold-1',
				'name' => 'Low engagement',
				'kind' => 'low-engagement',
				'scope' => 'per-learner',
				'cohortId' => null,
				'metric' => 'engagement-score-below',
				'limit' => 30,
				'lifecycle' => 'active',
			]
		);

		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
			now: $now
		);

		$this->runOne($handler, $this->entryFrom(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		$flags = $this->savedFor('engagement-risk-flag');
		self::assertCount(1, $flags);
		self::assertSame('learner-1', $flags[0]['learnerId']);
		self::assertSame('threshold-1', $flags[0]['engagementRiskThresholdId']);
		self::assertSame('open', $flags[0]['lifecycle']);
		self::assertSame(20.0, $flags[0]['metricValueAtFlag']);

	}//end testFlagCreatedOnFirstCrossing()

	/**
	 * No duplicate flag is created while one is already open for the same
	 * learner + threshold.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
	 */
	public function testNoDuplicateFlagWhileOpen(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'engagement-risk-threshold',
			[
				'id' => 'threshold-1',
				'scope' => 'per-learner',
				'cohortId' => null,
				'metric' => 'engagement-score-below',
				'limit' => 30,
				'lifecycle' => 'active',
			]
		);
		$this->seed(
			'engagement-risk-flag',
			[
				'id' => 'flag-1',
				'learnerId' => 'learner-1',
				'engagementRiskThresholdId' => 'threshold-1',
				'lifecycle' => 'open',
			]
		);

		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
			now: $now
		);

		$this->runOne($handler, $this->entryFrom(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		self::assertCount(0, $this->savedFor('engagement-risk-flag'));

	}//end testNoDuplicateFlagWhileOpen()

	/**
	 * A resolved flag does not block a new flag on a later relapse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-a-resolved-flag-does-not-block-re-flagging-on-a-later-relapse
	 */
	public function testResolvedFlagAllowsNewFlagOnRelapse(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed(
			'engagement-risk-threshold',
			[
				'id' => 'threshold-1',
				'scope' => 'per-learner',
				'cohortId' => null,
				'metric' => 'engagement-score-below',
				'limit' => 30,
				'lifecycle' => 'active',
			]
		);
		$this->seed(
			'engagement-risk-flag',
			[
				'id' => 'flag-1',
				'learnerId' => 'learner-1',
				'engagementRiskThresholdId' => 'threshold-1',
				'lifecycle' => 'resolved',
			]
		);

		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
			now: $now
		);

		$this->runOne($handler, $this->entryFrom(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		self::assertCount(1, $this->savedFor('engagement-risk-flag'));

	}//end testResolvedFlagAllowsNewFlagOnRelapse()

	/**
	 * A per-cohort threshold does not fire for a learner who is not a
	 * member of the scoped Cohort.
	 *
	 * @return void
	 */
	public function testCohortScopedThresholdSkipsNonMember(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));

		$this->seed('cohort', ['id' => 'cohort-1', 'learnerIds' => ['learner-2', 'learner-3']]);
		$this->seed(
			'engagement-risk-threshold',
			[
				'id' => 'threshold-1',
				'scope' => 'per-cohort',
				'cohortId' => 'cohort-1',
				'metric' => 'engagement-score-below',
				'limit' => 30,
				'lifecycle' => 'active',
			]
		);

		$handler = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 1.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 20],
			now: $now
		);

		$this->runOne($handler, $this->entryFrom(
				['verified_actor_id' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']
			)
		);

		self::assertCount(0, $this->savedFor('engagement-risk-flag'));

	}//end testCohortScopedThresholdSkipsNonMember()

	/**
	 * No AI/ML client, HTTP call, or Hermiq dependency is constructed
	 * anywhere by this handler — verified structurally: it depends only on
	 * ObjectService, EngagementScoreEvaluator, ListenerSchemaResolver, and
	 * ITimeFactory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
	 */
	/**
	 * An entry missing either id is skipped rather than recomputed against an
	 * empty string. The deferral buffer is fed by listeners, so a malformed
	 * entry is a thing that reaches here, not a thing that cannot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testAnEntryMissingAnIdIsSkipped(): void {
		$job = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 12.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 55],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$this->runOne($job, ['learnerId' => 'learner-1', 'courseId' => '', 'tenantId' => 'tenant-a']);
		$this->runOne($job, ['learnerId' => '', 'courseId' => 'course-1', 'tenantId' => 'tenant-a']);

		self::assertCount(
			0,
			$this->savedFor('engagement-score'),
			'an incomplete entry must not produce a score row keyed on an empty id'
		);

	}//end testAnEntryMissingAnIdIsSkipped()

	/**
	 * THE REASON THE PER-ENTRY CATCH EXISTS. A deferred job is handed a CHUNK;
	 * if one bad entry threw out of the loop, every later entry in that chunk
	 * would be silently dropped and never retried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testOneFailingEntryDoesNotLoseTheRestOfTheChunk(): void {
		$now = new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'));
		$job = $this->makeHandler(
			evaluated: ['timeOnTaskMinutes' => 12.0, 'lastActivityAt' => '2026-07-13T09:00:00+02:00', 'score' => 55],
			now: $now
		);

		// The first entry blows up inside the evaluator; the second is fine.
		$this->evaluatorThrowsFor = 'boom-course';

		$method = new \ReflectionMethod($job, 'runDeferred');
		$method->setAccessible(true);
		$method->invoke(
			$job,
			new DeferredListenerContext(
				userId: 'learner-1',
				orgUuid: null,
				entries: [
					['learnerId' => 'learner-1', 'courseId' => 'boom-course', 'tenantId' => 'tenant-a'],
					['learnerId' => 'learner-2', 'courseId' => 'course-1', 'tenantId' => 'tenant-a'],
				]
			)
		);

		$scores = $this->savedFor('engagement-score');
		self::assertCount(
			1,
			$scores,
			'the entry AFTER the failing one must still have been processed'
		);
		self::assertSame('learner-2', $scores[0]['learnerId'] ?? null);

	}//end testOneFailingEntryDoesNotLoseTheRestOfTheChunk()

	/**
	 * `recency-days-above` compares whole days since lastActivityAt against
	 * the limit, and reports "not crossed" — never a crossing — when there is
	 * no usable timestamp. An unparseable date must not read as "infinitely
	 * stale" and flag every learner it touches.
	 *
	 * @param string|null $lastActivityAt The stamp under test.
	 * @param float       $limit          The threshold limit, in days.
	 * @param bool        $expected       Whether it counts as crossed.
	 *
	 * @return void
	 *
	 * @dataProvider recencyProvider
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testRecencyDaysAboveCrossing(?string $lastActivityAt, float $limit, bool $expected): void {
		$job = $this->makeHandler(
			evaluated: ['score' => 99],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$method = new \ReflectionMethod($job, 'isCrossed');
		$method->setAccessible(true);

		self::assertSame(
			$expected,
			$method->invoke($job, 'recency-days-above', $limit, ['lastActivityAt' => $lastActivityAt])
		);

	}//end testRecencyDaysAboveCrossing()

	/**
	 * Cases for {@see testRecencyDaysAboveCrossing}.
	 *
	 * @return array<string, array{0: string|null, 1: float, 2: bool}>
	 */
	public static function recencyProvider(): array {
		return [
			'ten days stale, limit 7'      => ['2026-07-03T10:00:00+02:00', 7.0, true],
			'one day stale, limit 7'       => ['2026-07-12T10:00:00+02:00', 7.0, false],
			'exactly at the limit'         => ['2026-07-06T10:00:00+02:00', 7.0, false],
			'null stamp is not a crossing' => [null, 7.0, false],
			'empty stamp is not a crossing' => ['', 7.0, false],
			'unparseable is not a crossing' => ['not-a-date', 7.0, false],
		];
	}//end recencyProvider()

	/**
	 * `engagement-score-below` is a strict comparison, and a missing score is
	 * not a crossing — absence of a measurement is not a low measurement.
	 *
	 * @param mixed $score    The score on the EngagementScore, or null.
	 * @param float $limit    The threshold limit.
	 * @param bool  $expected Whether it counts as crossed.
	 *
	 * @return void
	 *
	 * @dataProvider scoreBelowProvider
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testEngagementScoreBelowCrossing(mixed $score, float $limit, bool $expected): void {
		$job = $this->makeHandler(
			evaluated: ['score' => 99],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$method = new \ReflectionMethod($job, 'isCrossed');
		$method->setAccessible(true);

		self::assertSame(
			$expected,
			$method->invoke($job, 'engagement-score-below', $limit, ['score' => $score])
		);

	}//end testEngagementScoreBelowCrossing()

	/**
	 * Cases for {@see testEngagementScoreBelowCrossing}.
	 *
	 * @return array<string, array{0: mixed, 1: float, 2: bool}>
	 */
	public static function scoreBelowProvider(): array {
		return [
			'below the limit'            => [40, 50.0, true],
			'above the limit'            => [60, 50.0, false],
			'exactly at the limit'       => [50, 50.0, false],
			'missing score is no crossing' => [null, 50.0, false],
		];
	}//end scoreBelowProvider()

	/**
	 * An unknown metric never crosses. A threshold row carrying a metric this
	 * job does not implement must raise nothing at all, rather than falling
	 * through to whichever branch happens to be last.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testAnUnknownMetricNeverCrosses(): void {
		$job = $this->makeHandler(
			evaluated: ['score' => 0],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$method = new \ReflectionMethod($job, 'isCrossed');
		$method->setAccessible(true);

		self::assertFalse(
			$method->invoke($job, 'attendance-below', 100.0, ['score' => 0, 'lastActivityAt' => null])
		);

	}//end testAnUnknownMetricNeverCrosses()

	/**
	 * The value stamped onto a new flag is the one its own metric measures —
	 * days for a recency threshold, the score otherwise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
	 */
	public function testResolveMetricValuePicksTheMetricsOwnNumber(): void {
		$job = $this->makeHandler(
			evaluated: ['score' => 0],
			now: new DateTime('2026-07-13 10:00:00', new DateTimeZone('Europe/Amsterdam'))
		);

		$method = new \ReflectionMethod($job, 'resolveMetricValue');
		$method->setAccessible(true);

		$score = ['score' => 42, 'lastActivityAt' => '2026-07-03T10:00:00+02:00'];
		self::assertSame(10.0, $method->invoke($job, 'recency-days-above', $score));
		self::assertSame(42.0, $method->invoke($job, 'engagement-score-below', $score));
		self::assertSame(
			0.0,
			$method->invoke($job, 'recency-days-above', ['score' => 42, 'lastActivityAt' => null]),
			'an unusable stamp resolves to 0 days rather than null'
		);

	}//end testResolveMetricValuePicksTheMetricsOwnNumber()

	public function testConstructorHasNoAiOrHermiqDependency(): void {
		$reflection = new \ReflectionClass(EngagementSignalJob::class);
		$constructor = $reflection->getConstructor();
		self::assertNotNull($constructor);

		$paramTypes = array_map(
			static fn (\ReflectionParameter $p) => (string)$p->getType(),
			$constructor->getParameters()
		);

		foreach ($paramTypes as $type) {
			self::assertStringNotContainsStringIgnoringCase('hermiq', $type);
			self::assertStringNotContainsStringIgnoringCase('aifeature', $type);
		}

	}//end testConstructorHasNoAiOrHermiqDependency()
}//end class
